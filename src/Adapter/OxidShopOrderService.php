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
     * Order creation itself needs no collaborators — it reads the request and
     * talks to OXID. Cancelling one does: that is the same storno + voucher
     * release the cleanup command performs, and it is written once.
     */
    public function __construct(
        private readonly NotFinishedOrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        try {
            [$basket, $user] = $this->validateBasketAndUser($request);
            [$order, $orderState] = $this->finalizeAndValidateOrder($basket, $user, $request);
            $this->setOrderFieldsAfterCreation($order, $request);
            return $this->buildOrderResponse($order, $basket, $user, $orderState, $request);
        } catch (ShopOrderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->wrapOrderCreationError($e, $request);
        }
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

        /** @var Order $order */
        $order = oxNew(Order::class);
        /** @var int $orderState */
        $orderState = $order->finalizeOrder($basket, $user, false);

        $this->validateOrderState($orderState, $request, $basket);

        return [$order, $orderState];
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
     * Validate basket and user exist in session.
     *
     * @return array{Basket, User}
     * @throws ShopOrderException
     */
    private function validateBasketAndUser(CreateOrderRequest $request): array
    {
        /** @var Basket|null $basket */
        $basket = Registry::getSession()->getBasket();
        if (!$basket) {
            throw new ShopOrderException(
                message: 'Basket not found in session',
                errorCode: 'basket_not_found',
                context: ['session_id' => $request->sessionId]
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
        if (in_array($orderState, [Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS], true)) {
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

        $order->save();
        $order->setOrderNumber(); // @phpstan-ignore method.notFound

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
            Order::ORDER_STATE_ORDEREXISTS => 'completed',
            Order::ORDER_STATE_MAILINGERROR => 'completed', // Order created but email failed
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
