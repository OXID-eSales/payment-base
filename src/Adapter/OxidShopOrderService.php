<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use DateTimeImmutable;
use Exception;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Exception\ArticleException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Basket\SessionBasketProvider;
use OxidEsales\PaymentBase\Checkout\Guard\CheckoutAttemptGuardInterface;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\DefersConfirmationMailInterface;
use OxidEsales\PaymentBase\Repository\ContractOrderMailStoreInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use Throwable;

/**
 * The one implementation that finalizes orders, for every provider.
 *
 * Until 2026-09-01 each PSP module shipped its own copy of this class and each
 * aliased `ShopOrderServiceInterface` to it. Because that is a single container
 * id, exactly one of them won — whichever module's services.yaml merged last —
 * and that one finalized *every* provider's orders. On one installation Stripe's
 * copy was finalizing Mollie payments; the only reason it was noticed is that
 * its log line carries a `basket_total` field the others do not.
 *
 * The four copies were not actually provider-specific: all of them read
 * everything they need off {@see CreateOrderRequest} and branch on nothing.
 * Mollie's and PayPal's were Stripe's minus metadata storage. So the fix is not
 * to route per provider but to keep one implementation, here, where the shared
 * contract already lives.
 *
 * @since 1.0.0
 */
class OxidShopOrderService implements ShopOrderServiceInterface
{
    /**
     * Error code of the ShopOrderException thrown when core reports that an
     * order for the current `sess_challenge` already exists (MOL-18).
     */
    public const ERROR_ORDER_EXISTS = 'order_exists';

    private readonly CheckoutBasketProviderInterface $basketProvider;

    /**
     * Cancelling an order needs the repository for the same storno + voucher
     * release the cleanup command performs, written once. Sprint 10
     * (2026-09-23): order creation now needs a collaborator too — the
     * post-creation step copies billing into an empty shipping address, and
     * that field mapping is its own class rather than inlined here.
     *
     * Sprint 15 / S1 (2026-10-06): the basket is asked from a provider, so a
     * headless checkout (GraphQL Storefront, MCP: basket persisted by id, no
     * session) and the Twig checkout share this one service. Optional, with
     * the session provider as default, so a consumer whose services.yaml
     * predates this keeps its byte-identical behaviour.
     *
     * Sprint 15 / S3: the attempt guard gives the headless path the
     * one-order-per-attempt rule core enforces for sessions through
     * `sess_challenge`. Optional for the same reason; without it the service
     * behaves exactly as before.
     *
     * STRP-176 (1.6.0): the order mails rendered at creation wait on the
     * contract the request names. Optional for the same reason; without the
     * store they are sent at once, as before STRP-176, and without the sender
     * too they are lost - logged at ERROR.
     */
    public function __construct(
        private readonly NotFinishedOrderRepositoryInterface $orderRepository,
        private readonly OrderShippingAddressCopier $shippingAddressCopier,
        ?CheckoutBasketProviderInterface $basketProvider = null,
        private readonly ?CheckoutAttemptGuardInterface $attemptGuard = null,
        private readonly ?ContractOrderMailStoreInterface $orderMails = null,
        private readonly ?ShopMailSenderInterface $mailSender = null
    ) {
        $this->basketProvider = $basketProvider ?? new SessionBasketProvider();
    }

    /**
     * @inheritDoc
     */
    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        // Claimed before the basket is even loaded: the window between two
        // concurrent submissions is the whole point.
        $this->attemptGuard?->claim($request);

        try {
            [$basket, $user] = $this->validateBasketAndUser($request);
            [$order, $orderState] = $this->finalizeAndValidateOrder($basket, $user, $request);
            $this->setOrderFieldsAfterCreation($order, $request);
            $response = $this->buildOrderResponse($order, $basket, $user, $orderState, $request);
        } catch (ShopOrderException $e) {
            $this->attemptGuard?->release($request);
            throw $e;
        } catch (Throwable $e) {
            $this->attemptGuard?->release($request);
            throw $this->wrapOrderCreationError($e, $request);
        }

        $this->attemptGuard?->complete($request, $response->orderId);

        return $response;
    }

    /**
     * Translate an unexpected order-creation failure into a ShopOrderException.
     *
     * OXID ArticleExceptions (article turned unbuyable, out of stock, or removed
     * between checkout-page render and finalizeOrder) are mapped to the
     * dedicated 'article_not_buyable' code so the controller can surface a
     * structured 409 instead of a generic 500. This is the race-window safety
     * net behind the controller's pre-dispatch buyability check.
     *
     * Story 3 (unbuyable-article-checkout).
     */
    private function wrapOrderCreationError(Throwable $e, CreateOrderRequest $request): ShopOrderException
    {
        if ($e instanceof ArticleException) {
            return new ShopOrderException(
                message: $e->getMessage(),
                errorCode: 'article_not_buyable',
                context: [
                    'exception_class' => get_class($e),
                    'session_id' => $request->sessionId,
                ],
                previous: $e
            );
        }

        return new ShopOrderException(
            message: 'Unexpected error during order creation: ' . $e->getMessage(),
            errorCode: 'unexpected_error',
            context: [
                'exception_class' => get_class($e),
                'session_id' => $request->sessionId,
            ],
            previous: $e
        );
    }

    /**
     * Finalize the order via OXID and validate its resulting state.
     *
     * Stores the order remark in session if provided, calls finalizeOrder(),
     * and delegates state validation to validateOrderState().
     *
     * @return array{Order, int} The finalized order and its OXID state code
     * @throws ShopOrderException on invalid order state
     */
    private function finalizeAndValidateOrder(
        Basket $basket,
        User $user,
        CreateOrderRequest $request
    ): array {
        if ($request->orderRemark !== null) {
            Registry::getSession()->setVariable('ordRem', $request->orderRemark);
        }

        $order = $this->newOrder();
        // STRP-176: this order exists before the customer pays at the PSP, and
        // core's finalizeOrder() ends by mailing "thank you for your order". The
        // mails are kept on the contract instead and sent when it commits. Not
        // deferrable only when the Order extension is missing from the chain:
        // then the mail goes out now, as before.
        if ($order instanceof DefersConfirmationMailInterface) {
            $order->deferConfirmationMail();
        }
        // OXID 7.6: Order::getDelAddressInfo() resolves a selected delivery
        // address (`deladrid`) only when the ORDER's active user owns it
        // (getUser()->getUserAddresses()), and Base::getUser() falls back to
        // loadActiveUser() — empty in a headless checkout. Without this,
        // validateDeliveryAddress() drops the address from the expected hash
        // and the checkout dies with invalid_delivery_address (state 7).
        // Harmless on 7.4/7.5, where getDelAddressInfo() never asks the user.
        //
        // Base::$_oActUser is STATIC — scope the override to the finalize call
        // and reset to null (= unresolved, so the next getUser() re-resolves
        // from the session) afterwards. Leaving it set poisons every later
        // active-user read in the process: delivery-set resolution for the
        // next basket would run against this checkout's user.
        // @phpstan-ignore method.notFound (Core\Base::setUser() exists on every edition; invisible through OXID's class-chain stubs)
        $order->setUser($this->activeUserForFinalize($user));
        try {
            /** @var int $orderState */
            $orderState = $order->finalizeOrder($basket, $user, false);
        } finally {
            $this->clearActiveUser($user);
        }

        $this->refuseSecondSubmission($orderState, $request);
        $this->validateOrderState($orderState, $request, $basket);
        $this->keepOrderMailsOnContract($order, $request);

        return [$order, $orderState];
    }

    /**
     * STRP-176 — the order knows its mails, the request knows the contract.
     *
     * Every failure ends in an immediate send: the order exists and the
     * checkout goes on either way, and a mail sent before the payment (the
     * behaviour before STRP-176) beats one that never comes.
     */
    private function keepOrderMailsOnContract(Order $order, CreateOrderRequest $request): void
    {
        if (!$order instanceof DefersConfirmationMailInterface) {
            return;
        }
        $mails = $order->takeDeferredMails();
        if ($mails === []) {
            return;
        }

        $contractId = $request->metadata['contract_id'] ?? null;
        $context = ['order_id' => (string) $order->getId(), 'contract_id' => $contractId];
        if ($this->orderMails === null || !is_string($contractId) || $contractId === '') {
            $this->sendOrderMailsNow($mails, $order, 'warning', 'no contract to keep the order mails on', $context);

            return;
        }

        try {
            if (!$this->orderMails->stash($contractId, $mails)) {
                $this->sendOrderMailsNow($mails, $order, 'warning', 'contract row not found', $context);
            }
        } catch (Throwable $e) {
            $context['error'] = $e->getMessage();
            $this->sendOrderMailsNow($mails, $order, 'error', 'order mails could not be kept', $context);
        }
    }

    /**
     * @param list<DeferredOrderMail> $mails
     * @param array<string, mixed> $context
     */
    private function sendOrderMailsNow(array $mails, Order $order, string $level, string $why, array $context): void
    {
        Registry::getLogger()->log($level, '[STRP-176] ' . $why . ', order mails sent before the payment', $context);
        if ($this->mailSender === null) {
            Registry::getLogger()->error('[STRP-176] no mail sender, the order mails are lost', $context);

            return;
        }

        foreach ($mails as $mail) {
            try {
                if (!$this->mailSender->send($mail, (int) $order->getFieldData('oxshopid'))) {
                    Registry::getLogger()->error('[STRP-176] order mail not sent', $context + ['kind' => $mail->kind]);
                }
            } catch (Throwable $e) {
                Registry::getLogger()->error(
                    '[STRP-176] order mail not sent',
                    $context + ['kind' => $mail->kind, 'error' => $e->getMessage()]
                );
            }
        }
    }

    /**
     * MOL-18: core answers ORDER_STATE_ORDEREXISTS when `sess_challenge`
     * already names an oxorder row - its own guard against "Order now" clicked
     * twice ("somebody clicked like mad on order button"). The Order object was
     * never loaded from the basket in that case, so saving it - which this
     * class did until 2026-09-24 - wrote a SECOND row: fresh id, no user, no
     * articles, no payment type, total 0, and the shopper was sent to the PSP
     * to pay for it. Eight such rows in one dev database carried committed
     * contracts. The signal ends here, as an error the caller can name.
     *
     * @throws ShopOrderException with code {@see self::ERROR_ORDER_EXISTS}
     */
    private function refuseSecondSubmission(int $orderState, CreateOrderRequest $request): void
    {
        if ($orderState !== Order::ORDER_STATE_ORDEREXISTS) {
            return;
        }

        $challenge = $this->sessionChallenge();
        Registry::getLogger()->warning('OxidShopOrderService: refused a second submission of one checkout attempt', [
            'order_id' => $challenge,
            'session_id' => $request->sessionId,
            'user_id' => $request->userId,
        ]);

        throw new ShopOrderException(
            message: 'An order for this checkout attempt already exists',
            errorCode: self::ERROR_ORDER_EXISTS,
            context: [
                'order_id' => $challenge,
                'session_id' => $request->sessionId,
                'user_id' => $request->userId,
            ]
        );
    }

    /**
     * The one oxNew() on the creation path, as a seam: unit tests hand in an
     * Order whose finalizeOrder() answers a chosen state without the shop.
     */
    protected function newOrder(): Order
    {
        /** @var Order $order */
        $order = oxNew(Order::class);

        return $order;
    }

    /**
     * The user handed to Order::setUser() before finalizeOrder().
     *
     * Freshly loaded on purpose: the checkout user may be a partially
     * populated wrapper (GraphQL Storefront's Shared\Shop\User), and the
     * Enterprise Order::setUser() clones the EE-only `oxuser__oxustidstatus`
     * field — "__clone method called on non-object" when that field was never
     * loaded. A load() by id guarantees the full row on every edition.
     */
    protected function activeUserForFinalize(User $user): User
    {
        /** @var User $freshUser */
        $freshUser = oxNew(User::class);
        if ($freshUser->load((string) $user->getId())) {
            return $freshUser;
        }

        return $user;
    }

    /**
     * Clear the process-wide active user (Base::$_oActUser is static; null
     * means "unresolved", so the next getUser() re-resolves from the session).
     *
     * Reset through the User instance on purpose: the Enterprise edition
     * overrides Order::setUser() and clones an EE-only field — a null there
     * fatals with "__clone method called on non-object" — while User inherits
     * the plain Core\Base setter.
     */
    private function clearActiveUser(User $user): void
    {
        // @phpstan-ignore method.notFound (Core\Base::setUser() exists on every edition; invisible through OXID's class-chain stubs)
        $user->setUser(null);
    }

    /**
     * The order id core will use for this checkout attempt (Registry-backed).
     */
    protected function sessionChallenge(): ?string
    {
        $challenge = Registry::getSession()->getVariable('sess_challenge');

        return is_string($challenge) && $challenge !== '' ? $challenge : null;
    }

    /**
     * Build OrderResponse from order creation results.
     */
    private function buildOrderResponse(
        Order $order,
        Basket $basket,
        User $user,
        int $orderState,
        CreateOrderRequest $request
    ): OrderResponse {
        $price = $basket->getPrice();
        // Sprint 133 (F7): no 'EUR' fallback — a mis-stamped currency on the
        // order response is worse than a loud failure.
        $currencyName = ShopCurrency::nameOf(
            $basket->getBasketCurrency(),
            'basket currency during order creation'
        );

        return new OrderResponse(
            orderId: (string) $order->getId(),
            orderNumber: $this->getIntField($order, 'oxordernr'),
            userId: (string) $user->getId(),
            totalAmount: $price ? (float) $price->getBruttoPrice() : 0.0, // @phpstan-ignore ternary.alwaysTrue
            currency: $currencyName,
            status: $this->mapOrderStateToStatus($orderState),
            paymentId: $request->paymentId,
            paymentTransactionId: $request->paymentTransactionId,
            createdAt: $this->getOrderCreationDate($order),
            metadata: $request->metadata,
            shopData: [
                'oxid_order_state' => $orderState,
                'oxid_order_id' => $order->getId(),
                'oxid_order_nr' => $order->getFieldData('oxordernr'),
            ]
        );
    }

    /**
     * The basket of this checkout attempt (from the provider) and its user.
     *
     * @return array{Basket, User}
     * @throws ShopOrderException
     */
    private function validateBasketAndUser(CreateOrderRequest $request): array
    {
        $basket = $this->basketProvider->basketFor($request);
        if (!$basket) {
            throw new ShopOrderException(
                message: 'Basket not found for this checkout attempt',
                errorCode: 'basket_not_found',
                context: ['session_id' => $request->sessionId, 'basket_id' => $request->basketId]
            );
        }

        /** @var User|null $user */
        $user = $basket->getBasketUser();
        if (!$user || !$user->getId()) {
            throw new ShopOrderException(
                message: 'User not found',
                errorCode: 'user_not_found',
                context: ['user_id' => $request->userId]
            );
        }

        return [$basket, $user];
    }

    /**
     * Validate order finalization state.
     *
     * @throws ShopOrderException
     */
    private function validateOrderState(int $orderState, CreateOrderRequest $request, Basket $basket): void
    {
        if ($orderState === Order::ORDER_STATE_OK) {
            return;
        }

        $errorCode = $this->mapOrderStateToErrorCode($orderState);
        $basketPrice = $basket->getPrice();
        Registry::getLogger()->error('OxidShopOrderService: Order finalization failed', [
            'order_state' => $orderState,
            'error_code' => $errorCode,
            'session_id' => $request->sessionId,
            'user_id' => $request->userId,
            'payment_id' => $request->paymentId,
            'basket_count' => $basket->getProductsCount(),
            // @phpstan-ignore ternary.alwaysTrue (OXID Basket::getPrice() PHPDoc is non-nullable, but defensive)
            'basket_total' => $basketPrice ? (float) $basketPrice->getBruttoPrice() : 0.0,
        ]);
        throw new ShopOrderException(
            message: 'Order finalization failed with state: ' . $orderState . ' (' . $errorCode . ')',
            errorCode: $errorCode,
            context: [
                'order_state' => $orderState,
                'session_id' => $request->sessionId,
                'user_id' => $request->userId,
            ]
        );
    }

    /**
     * Set order folder, transaction ID, order number, and metadata after creation.
     */
    private function setOrderFieldsAfterCreation(Order $order, CreateOrderRequest $request): void
    {
        $order->oxorder__oxfolder = new \OxidEsales\Eshop\Core\Field(
            'ORDERFOLDER_NEW',
            \OxidEsales\Eshop\Core\Field::T_RAW
        );

        if ($request->paymentTransactionId !== null) {
            $order->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field(
                $request->paymentTransactionId,
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
        }

        if ($request->initialStatus !== null) {
            $order->oxorder__oxtransstatus = new \OxidEsales\Eshop\Core\Field(
                $request->initialStatus,
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
        }

        $this->shippingAddressCopier->copyBillingWhenShippingEmpty($order);
        // finalizeOrder() has already drawn the order number (core setNumber()).
        // The former setOrderNumber() call here was a method the Stripe / OPC
        // Order extensions add, so order creation only worked where one of
        // those modules was active — CI's bare CE shop proved it.
        $order->save();

        if (!empty($request->metadata)) {
            $this->storeOrderMetadata($order, $request->metadata);
        }
    }

    /**
     * Cancel a NOT_FINISHED order and preserve it in the database.
     *
     * Sprint 88 (STRP-123): Orders are retained with OXSTORNO=1 and
     * OXTRANSSTATUS='CANCELLED' to preserve order number sequence (no gaps).
     * Vouchers are reset for reuse.
     *
     * Setting OXTRANSSTATUS to 'CANCELLED' (instead of keeping 'NOT_FINISHED')
     * prevents OXID's checkOrderExist() from treating the cancelled order as
     * a valid order for the same sess_challenge on retry.
     *
     * @inheritDoc
     */
    public function deleteNotFinishedOrder(string $orderId): bool
    {
        // Same storno + voucher release the cleanup command performs, and the
        // same guarded UPDATE: the write only applies while the order is still
        // NOT_FINISHED, so a checkout that finished in between is left alone.
        if (!$this->orderRepository->cancelOrder($orderId)) {
            return false;
        }

        $this->orderRepository->releaseVouchers($orderId);

        return true;
    }


    /**
     * Map OXID order state to normalized status string.
     */
    private function mapOrderStateToStatus(int $orderState): string
    {
        return match ($orderState) {
            Order::ORDER_STATE_OK => 'completed',
            // Not reached from createOrder(): validateOrderState() lets only OK
            // through. Since STRP-176 the order mail no longer goes out here, so
            // a mail problem cannot fail the order (the deferred path answers OK).
            Order::ORDER_STATE_MAILINGERROR => 'completed',
            Order::ORDER_STATE_PAYMENTERROR => 'payment_error',
            Order::ORDER_STATE_BELOWMINPRICE => 'below_minimum',
            Order::ORDER_STATE_INVALIDPAYMENT => 'invalid_payment',
            Order::ORDER_STATE_INVALIDDELIVERY => 'invalid_delivery',
            Order::ORDER_STATE_INVALIDDELADDRESSCHANGED => 'invalid_delivery',
            Order::ORDER_STATE_VOUCHERERROR => 'voucher_error',
            default => 'unknown',
        };
    }

    /**
     * Map OXID order state to error code.
     */
    private function mapOrderStateToErrorCode(int $orderState): string
    {
        return match ($orderState) {
            Order::ORDER_STATE_ORDEREXISTS => self::ERROR_ORDER_EXISTS,
            Order::ORDER_STATE_PAYMENTERROR => 'payment_error',
            Order::ORDER_STATE_BELOWMINPRICE => 'below_minimum_price',
            Order::ORDER_STATE_INVALIDPAYMENT => 'invalid_payment_method',
            Order::ORDER_STATE_INVALIDDELIVERY => 'invalid_delivery_method',
            Order::ORDER_STATE_INVALIDDELADDRESSCHANGED => 'invalid_delivery_address',
            Order::ORDER_STATE_VOUCHERERROR => 'voucher_error',
            default => 'order_creation_failed',
        };
    }

    /**
     * Get order creation date, or null when it cannot be determined.
     *
     * Sprint 133 · Story 11 (F11): an unparseable oxorderdate used to fall back
     * to "now" behind a bare comment, so a corrupt date silently became the
     * current time on the order response. OrderResponse::$createdAt is nullable,
     * so unknown can be represented honestly.
     */
    private function getOrderCreationDate(Order $order): ?DateTimeImmutable
    {
        $dateStr = $order->getFieldData('oxorderdate');

        if (!is_string($dateStr) || $dateStr === '') {
            Registry::getLogger()->warning('Order has no oxorderdate; reporting creation date as unknown', [
                'order_id' => $order->getId(),
            ]);

            return null;
        }

        try {
            return new DateTimeImmutable($dateStr);
        } catch (Exception $e) {
            Registry::getLogger()->warning('Order has an unparseable oxorderdate; reporting it as unknown', [
                'order_id' => $order->getId(),
                'oxorderdate' => $dateStr,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store additional metadata with the order.
     *
     * This can be extended to store custom data in oxorder table
     * or in a separate metadata table.
     *
     * @param Order $order
     * @param array<string, mixed> $metadata
     */
    private function storeOrderMetadata(Order $order, array $metadata): void
    {
        // Option 1: Store in oxorder.oxremark field
        // $order->oxorder__oxremark = new Field(json_encode($metadata));
        // $order->save();

        // Option 2: Store in custom table (implement if needed)
        // $this->metadataRepository->store($order->getId(), $metadata);

        // For now, just log the metadata
        Registry::getLogger()->debug('Order metadata', [
            'order_id' => $order->getId(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get an int field value from an OXID model safely.
     */
    private function getIntField(Order $order, string $fieldName): int
    {
        $value = $order->getFieldData($fieldName);
        return is_numeric($value) ? (int) $value : 0;
    }
}
