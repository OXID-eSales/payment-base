<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\PaymentContext;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScopeInterface;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlPolicyInterface;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlRejectedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Start: the return URLs pass the policy, the basket scope is entered (so the
 * open-attempt registry keys by basket and a retry retires the previous
 * attempt), the user basket row names the payment, the payment names the
 * provider handler, the handler gets a PaymentContext built from the user
 * basket by the S1 provider - and does what it does for the one-page
 * checkout. The contract gets a token the client must present on return and
 * cancel; the scope moves to the contract.
 *
 * Return: token, then the provider's resolver through CheckoutReturnResponder,
 * exactly like the provider's Twig controller. Cancel: token, then the same
 * cleaner a same-session retry uses.
 *
 * @since 3.0.0
 */
class HeadlessCheckoutService implements HeadlessCheckoutServiceInterface
{
    public const TOKEN_METADATA_KEY = 'headless_token';

    private const STOREFRONT_PAYMENT_FIELD = 'oegql_paymentid';

    public function __construct(
        private readonly PaymentHandlerRegistryInterface $handlers,
        private readonly ReturnResolverRegistryInterface $resolvers,
        private readonly CheckoutBasketProviderInterface $baskets,
        private readonly ReturnUrlPolicyInterface $returnUrls,
        private readonly HeadlessCheckoutScopeInterface $scope,
        private readonly ContractRepositoryInterface $contracts,
        private readonly CheckoutReturnResponder $responder,
        private readonly PreviousCheckoutAttemptCleanerInterface $cleaner,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(HeadlessStartRequest $request): HeadlessStartResult
    {
        $this->assertReturnUrls($request);

        $row = $this->loadUserBasket($request->basketId);
        if ($row === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::BASKET_NOT_FOUND,
                sprintf('Basket %s not found', $request->basketId)
            );
        }

        $paymentId = $this->paymentIdFor($row, $request);
        $handler = $paymentId !== '' ? $this->handlers->forPaymentMethod($paymentId) : null;
        if ($handler === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::PAYMENT_NOT_SUPPORTED,
                sprintf(
                    'Payment "%s" of basket %s is not a contract-first payment; use placeOrder',
                    $paymentId,
                    $request->basketId
                )
            );
        }

        if ($this->termsConsentRequired() && !$request->confirmTermsAndConditions) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::TERMS_NOT_CONFIRMED,
                'The shop requires consent to its terms and conditions (confirmTermsAndConditions)'
            );
        }

        // Entered before the handler runs: EarlyOrderCreationHandler's
        // open-attempt registry then keys by this basket, and the second
        // start for the same basket retires the first attempt.
        $this->scope->enter($request->basketId, $request->userId, $request->basketId);

        $basket = $this->basketFor($request, $paymentId);
        $user = $this->loadUser($request->userId);
        if ($user === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::USER_NOT_FOUND,
                sprintf('User %s not found', $request->userId)
            );
        }

        $result = $handler->processPayment(new PaymentContext(
            basket: $basket,
            user: $user,
            paymentMethodId: $paymentId,
            providerTransactionId: null,
            returnUrl: $request->returnUrl,
            cancelUrl: $request->cancelUrl,
            metadata: [
                'basketId' => $request->basketId,
                'uiMode' => $request->uiMode,
                'headless' => true,
                'sessionId' => 'headless:' . $request->basketId,
            ]
        ));

        if (!$result->isSuccess() || $result->getContractId() === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::PROVIDER_FAILED,
                sprintf('%s could not start the checkout: %s', $handler->getName(), (string) $result->getErrorMessage()),
                $result->getErrorCode()
            );
        }

        $contract = $this->contracts->findById($result->getContractId());
        if ($contract === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::CONTRACT_NOT_FOUND,
                sprintf('Contract %s not found after start', $result->getContractId())
            );
        }

        $token = $this->newToken();
        $contract->setMetadata(self::TOKEN_METADATA_KEY, $token);
        $this->contracts->save($contract);
        $this->scope->enter((string) $contract->getId(), $request->userId, $request->basketId);

        $clientSecret = $result->getClientSecret();
        $renderMode = $result->getMetadataValue('renderMode');

        return new HeadlessStartResult(
            contractId: (string) $contract->getId(),
            contractToken: $token,
            providerName: $handler->getId(),
            orderNumber: $this->orderNumberOf($contract),
            redirectUrl: $this->stringOrNull($result->getMetadataValue('redirectUrl')),
            clientSecret: $clientSecret,
            renderMode: is_string($renderMode) && $renderMode !== ''
                ? $renderMode
                : ($clientSecret !== null ? 'embedded' : 'redirect'),
        );
    }

    public function return(string $contractId, string $contractToken, array $providerParams): HeadlessReturnResult
    {
        $contract = $this->authorisedContract($contractId, $contractToken);

        if ($contract->getState()->isCommitted() || $contract->getState()->isFulfilled()) {
            return $this->returnResult($contract, $contract->getOrderId());
        }

        $provider = (string) $contract->getProvider();
        $resolver = $provider !== '' ? $this->resolvers->forProvider($provider) : null;
        if ($resolver === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::NO_RETURN_RESOLVER,
                sprintf('No return resolver registered for provider "%s" of contract %s', $provider, $contractId)
            );
        }

        $orderId = $this->responder->respond($provider, $contract, $resolver, $providerParams);

        return $this->returnResult($contract, $orderId);
    }

    public function cancel(string $contractId, string $contractToken): HeadlessCancelResult
    {
        $contract = $this->authorisedContract($contractId, $contractToken);

        $cancelled = $this->cleaner->clean((string) $contract->getId());
        $this->logger->info('[HeadlessCheckoutService] cancel', [
            'contractId' => $contract->getId(),
            'cancelled' => $cancelled,
            'state' => $contract->getStateValue(),
        ]);

        return new HeadlessCancelResult(
            cancelled: $cancelled,
            contractId: (string) $contract->getId(),
            contractState: $contract->getStateValue(),
        );
    }

    /**
     * The payment this basket pays with: what the storefront stored on the
     * row (`basketSetPayment`), else what a provider-specific mutation says
     * about itself. Both present and different is a client error - a basket
     * set to pay with X must not be started with Y.
     */
    private function paymentIdFor(UserBasket $row, HeadlessStartRequest $request): string
    {
        $rowPaymentId = (string) $row->getFieldData(self::STOREFRONT_PAYMENT_FIELD);
        $requested = (string) ($request->paymentId ?? '');

        if ($rowPaymentId !== '' && $requested !== '' && $rowPaymentId !== $requested) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::PAYMENT_NOT_SUPPORTED,
                sprintf(
                    'Basket %s is set to pay with "%s", not with "%s"',
                    $request->basketId,
                    $rowPaymentId,
                    $requested
                )
            );
        }

        return $rowPaymentId !== '' ? $rowPaymentId : $requested;
    }

    private function assertReturnUrls(HeadlessStartRequest $request): void
    {
        try {
            $this->returnUrls->assertAllowed($request->returnUrl);
            if ($request->cancelUrl !== null) {
                $this->returnUrls->assertAllowed($request->cancelUrl);
            }
        } catch (ReturnUrlRejectedException $e) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::RETURN_URL_REJECTED,
                $e->getMessage(),
                $e->reason
            );
        }
    }

    private function basketFor(HeadlessStartRequest $request, string $paymentId): object
    {
        try {
            $basket = $this->baskets->basketFor(new CreateOrderRequest(
                sessionId: 'headless:' . $request->basketId,
                userId: $request->userId,
                paymentId: $paymentId,
                basketId: $request->basketId,
            ));
        } catch (ShopOrderException $e) {
            throw new HeadlessCheckoutException($e->getErrorCode(), $e->getMessage());
        }

        if ($basket === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::BASKET_NOT_FOUND,
                sprintf('Basket %s not found', $request->basketId)
            );
        }

        return $basket;
    }

    /**
     * @throws HeadlessCheckoutException
     */
    private function authorisedContract(string $contractId, string $contractToken): PaymentContractInterface
    {
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::CONTRACT_NOT_FOUND,
                sprintf('Contract %s not found', $contractId)
            );
        }

        $expected = $contract->getMetadata(self::TOKEN_METADATA_KEY);
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $contractToken)) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::INVALID_TOKEN,
                sprintf('The contract token does not authorise contract %s', $contractId)
            );
        }

        $basketId = $contract->getMetadata('basket_id');
        $this->scope->enter(
            (string) $contract->getId(),
            $contract->getUserId(),
            is_string($basketId) && $basketId !== '' ? $basketId : null
        );

        return $contract;
    }

    private function returnResult(PaymentContractInterface $contract, ?string $orderId): HeadlessReturnResult
    {
        $state = $contract->getState();
        $settled = $state->isCommitted() || $state->isFulfilled();

        $status = match (true) {
            $settled => HeadlessReturnResult::COMMITTED,
            $state->isTerminal() => HeadlessReturnResult::FAILED,
            default => HeadlessReturnResult::PENDING,
        };

        return new HeadlessReturnResult(
            status: $status,
            orderId: $settled ? ($orderId ?? $contract->getOrderId()) : null,
            orderNumber: $this->orderNumberOf($contract),
            contractState: $contract->getStateValue(),
        );
    }

    private function orderNumberOf(PaymentContractInterface $contract): ?string
    {
        $number = $contract->getMetadata('order_number');

        return is_scalar($number) && (string) $number !== '' ? (string) $number : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Seam: the `oxuserbaskets` row.
     */
    protected function loadUserBasket(string $basketId): ?UserBasket
    {
        /** @var UserBasket $row */
        $row = oxNew(UserBasket::class);

        return $row->load($basketId) ? $row : null;
    }

    /**
     * Seam: the shop user behind the JWT.
     */
    protected function loadUser(string $userId): ?User
    {
        /** @var User $user */
        $user = oxNew(User::class);

        return $user->load($userId) ? $user : null;
    }

    /**
     * Seam: the shop's `blConfirmAGB`.
     */
    protected function termsConsentRequired(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blConfirmAGB');
    }

    /**
     * Seam: the token the client must present on return and cancel.
     */
    protected function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
