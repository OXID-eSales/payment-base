<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutService;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistry;
use OxidEsales\PaymentBase\Checkout\Headless\ReturnResolverRegistry;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlPolicyInterface;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlRejectedException;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HeadlessUserBasketRow extends UserBasket
{
    public function __construct(private readonly string $ownerId, private readonly string $paymentId)
    {
    }

    public function getFieldData(string $field): mixed
    {
        return match ($field) {
            'oxuserid' => $this->ownerId,
            'oegql_paymentid' => $this->paymentId,
            default => null,
        };
    }
}

final class HeadlessShopUser extends User
{
    public function getId(): ?string
    {
        return 'user-1';
    }
}

final class HeadlessShopBasket extends Basket
{
    public function __construct()
    {
    }
}

final class AllowingPolicy implements ReturnUrlPolicyInterface
{
    /** @var list<string> */
    public array $checked = [];

    public function __construct(private readonly ?string $rejects = null)
    {
    }

    public function assertAllowed(string $url): string
    {
        $this->checked[] = $url;
        if ($url === $this->rejects) {
            throw new ReturnUrlRejectedException($url, ReturnUrlRejectedException::ORIGIN_NOT_ALLOWED);
        }

        return $url;
    }

    public function isAllowed(string $url): bool
    {
        return $url !== $this->rejects;
    }
}

final class RecordingBasketProvider implements CheckoutBasketProviderInterface
{
    public ?CreateOrderRequest $askedWith = null;

    public function __construct(private readonly ?Basket $basket = new HeadlessShopBasket(), private readonly ?ShopOrderException $throws = null)
    {
    }

    public function basketFor(CreateOrderRequest $request): ?Basket
    {
        $this->askedWith = $request;
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->basket;
    }
}

/**
 * The service with its shop seams replaced: the user-basket row, the shop
 * user, the AGB setting, the token.
 */
final class TestableHeadlessCheckoutService extends HeadlessCheckoutService
{
    /** @var array<string, UserBasket> */
    public array $rows = [];

    public bool $termsRequired = true;

    protected function loadUserBasket(string $basketId): ?UserBasket
    {
        return $this->rows[$basketId] ?? null;
    }

    protected function loadUser(string $userId): ?User
    {
        return $userId === 'user-1' ? new HeadlessShopUser() : null;
    }

    protected function termsConsentRequired(): bool
    {
        return $this->termsRequired;
    }

    protected function newToken(): string
    {
        return 'tok-fixed';
    }
}

/**
 * Sprint 15 / S6 — the headless checkout in three calls, provider-agnostic,
 * on the seams S1–S5 built: start (policy, scope, user basket, provider
 * handler = today's "Place Order"), return (token, CheckoutReturnResponder
 * with the provider's resolver), cancel (token, retire the attempt). Each
 * provider's GraphQL mutations are a thin shell around this.
 */
final class HeadlessCheckoutServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;

    private PreviousCheckoutAttemptCleanerInterface&MockObject $cleaner;

    private CheckoutReturnResponder&MockObject $responder;

    private HeadlessCheckoutScope $scope;

    private FakePaymentHandler $stripe;

    private RecordingBasketProvider $baskets;

    private AllowingPolicy $policy;

    private ReturnResolverInterface&MockObject $stripeResolver;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->cleaner = $this->createMock(PreviousCheckoutAttemptCleanerInterface::class);
        $this->responder = $this->createMock(CheckoutReturnResponder::class);
        $this->scope = new HeadlessCheckoutScope();
        $this->stripe = new FakePaymentHandler('stripe', ['oe_payments_stripe_wallet']);
        $this->baskets = new RecordingBasketProvider();
        $this->policy = new AllowingPolicy();
        $this->stripeResolver = $this->createMock(ReturnResolverInterface::class);
    }

    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(HeadlessCheckoutServiceInterface::class, $this->service());
    }

    // ---------------------------------------------------------------- start

    public function testStartRunsTheProviderHandlerForTheUserBasketAndAnswersWhatTheClientNeeds(): void
    {
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('order_number', '1001');
        $this->contracts->method('findById')->with('contract-1')->willReturn($contract);
        $this->contracts->expects($this->once())->method('save')->with($contract);

        $result = $service->start($this->startRequest());

        // the provider handler got a PaymentContext built from the user basket
        $context = $this->stripe->processedWith;
        self::assertNotNull($context);
        self::assertSame('oe_payments_stripe_wallet', $context->getPaymentMethodId());
        self::assertSame('https://app.example.com/return', $context->getReturnUrl());
        self::assertSame('https://app.example.com/cancel', $context->getCancelUrl());
        self::assertSame('ub-1', $context->getMetadataValue('basketId'));
        self::assertSame('hosted', $context->getMetadataValue('uiMode'));
        self::assertTrue($context->getMetadataValue('headless'));
        self::assertSame('user-1', $context->getUser()->getId());
        self::assertSame('ub-1', $this->baskets->askedWith?->basketId, 'the basket came from the user-basket provider');
        self::assertSame('user-1', $this->baskets->askedWith?->userId);

        // the client gets contract, token, order number and how to render
        self::assertSame('contract-1', $result->contractId);
        self::assertSame('tok-fixed', $result->contractToken);
        self::assertSame('1001', $result->orderNumber);
        self::assertSame('https://psp.example/pay/cs_1', $result->redirectUrl);
        self::assertNull($result->clientSecret);
        self::assertSame('redirect', $result->renderMode);
        self::assertSame('stripe', $result->providerName);

        // the token is on the contract, the scope moved to the contract
        self::assertSame('tok-fixed', $contract->getMetadata('headless_token'));
        self::assertSame('contract-1', $this->scope->getScopeId());
        self::assertSame('ub-1', $this->scope->getBasketId());

        self::assertSame(['https://app.example.com/return', 'https://app.example.com/cancel'], $this->policy->checked);
    }

    public function testStartEntersTheBasketScopeBeforeTheHandlerRunsSoTheRegistryKeysByBasket(): void
    {
        $handler = new class ('stripe', ['oe_payments_stripe_wallet'], $this->scope) extends FakePaymentHandler {
            public ?string $scopeWhenCalled = null;

            /** @param list<string> $ids */
            public function __construct(string $id, array $ids, private readonly HeadlessCheckoutScope $scope)
            {
                parent::__construct($id, $ids);
            }

            public function processPayment(\OxidEsales\PaymentBase\Adapter\PaymentContextInterface $context): PaymentHandlerResult
            {
                $this->scopeWhenCalled = $this->scope->getScopeId();

                return parent::processPayment($context);
            }
        };
        $this->stripe = $handler;
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');
        $this->contracts->method('findById')->willReturn($this->pendingContract('contract-1'));

        $service->start($this->startRequest());

        self::assertSame('ub-1', $handler->scopeWhenCalled);
    }

    public function testStartRefusesAReturnUrlThePolicyRejectsBeforeTouchingAnything(): void
    {
        $this->policy = new AllowingPolicy(rejects: 'https://evil.example/return');
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');

        try {
            $service->start($this->startRequest(returnUrl: 'https://evil.example/return'));
            self::fail('a rejected return URL must stop the checkout');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::RETURN_URL_REJECTED, $e->errorCode);
        }

        self::assertNull($this->stripe->processedWith);
        self::assertFalse($this->scope->isActive());
    }

    public function testStartRefusesAnUnknownBasket(): void
    {
        $this->expectExceptionObject(new HeadlessCheckoutException(HeadlessCheckoutException::BASKET_NOT_FOUND, 'Basket ub-1 not found'));

        $this->service()->start($this->startRequest());
    }

    public function testStartRefusesABasketWithoutAContractFirstPayment(): void
    {
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oxidpayadvance');

        try {
            $service->start($this->startRequest());
            self::fail('core payments go through core placeOrder');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::PAYMENT_NOT_SUPPORTED, $e->errorCode);
        }
    }

    /**
     * A provider-specific mutation knows its payment; a basket the client
     * never ran through basketSetPayment (no storefront row payment) still
     * starts. The row wins when it has one; a contradiction is a client error.
     */
    public function testStartTakesThePaymentFromTheRequestWhenTheRowHasNone(): void
    {
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', '');
        $this->contracts->method('findById')->willReturn($this->pendingContract('contract-1'));

        $result = $service->start($this->startRequest(paymentId: 'oe_payments_stripe_wallet'));

        self::assertSame('contract-1', $result->contractId);
        self::assertSame('oe_payments_stripe_wallet', $this->stripe->processedWith?->getPaymentMethodId());
    }

    public function testStartRefusesWhenTheRowPaysWithAnotherPayment(): void
    {
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_mollie');

        try {
            $service->start($this->startRequest(paymentId: 'oe_payments_stripe_wallet'));
            self::fail('a Mollie basket must not be started with Stripe');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::PAYMENT_NOT_SUPPORTED, $e->errorCode);
            self::assertStringContainsString('oe_payments_mollie', $e->getMessage());
        }
        self::assertNull($this->stripe->processedWith);
    }

    public function testStartRefusesWithoutTermsConsentWhenTheShopRequiresIt(): void
    {
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');

        try {
            $service->start($this->startRequest(confirmTerms: false));
            self::fail('blConfirmAGB is on');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::TERMS_NOT_CONFIRMED, $e->errorCode);
        }
        self::assertNull($this->stripe->processedWith);
    }

    public function testStartDoesNotAskForTermsWhenTheShopDoesNot(): void
    {
        $service = $this->service();
        $service->termsRequired = false;
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');
        $this->contracts->method('findById')->willReturn($this->pendingContract('contract-1'));

        $result = $service->start($this->startRequest(confirmTerms: false));

        self::assertSame('contract-1', $result->contractId);
    }

    public function testStartTranslatesABasketRefusalFromTheProvider(): void
    {
        $this->baskets = new RecordingBasketProvider(throws: new ShopOrderException('foreign', 'basket_forbidden'));
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');

        try {
            $service->start($this->startRequest());
            self::fail('another user\'s basket');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame('basket_forbidden', $e->errorCode);
        }
    }

    public function testStartReportsAProviderFailure(): void
    {
        $this->stripe = new FakePaymentHandler('stripe', ['oe_payments_stripe_wallet'], PaymentHandlerResult::error('Stripe is down', 'STRIPE_API'));
        $service = $this->service();
        $service->rows['ub-1'] = new HeadlessUserBasketRow('user-1', 'oe_payments_stripe_wallet');

        try {
            $service->start($this->startRequest());
            self::fail('provider failure must surface');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::PROVIDER_FAILED, $e->errorCode);
            self::assertSame('STRIPE_API', $e->providerCode);
            self::assertStringContainsString('Stripe is down', $e->getMessage());
        }
    }

    // --------------------------------------------------------------- return

    public function testReturnVerifiesTheTokenAndRunsTheProviderResolverThroughTheResponder(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $contract->setMetadata('order_number', '1001');
        $contract->setProvider('stripe', 'cs_1');
        $this->contracts->method('findById')->with('contract-1')->willReturn($contract);
        $this->responder->expects($this->once())->method('respond')
            ->with('stripe', $contract, $this->stripeResolver, ['checkoutSessionId' => 'cs_1'])
            ->willReturnCallback(function () use ($contract): string {
                $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
                $contract->commitToOrder('order-1');

                return 'order-1';
            });

        $result = $this->service()->return('contract-1', 'tok-fixed', ['checkoutSessionId' => 'cs_1']);

        self::assertSame('committed', $result->status);
        self::assertSame('order-1', $result->orderId);
        self::assertSame('1001', $result->orderNumber);
        self::assertSame('committed', $result->contractState);
        self::assertSame('contract-1', $this->scope->getScopeId(), 'the return request runs in the contract scope');
    }

    public function testReturnWithAWrongTokenIsRefusedAndNothingRuns(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $this->contracts->method('findById')->willReturn($contract);
        $this->responder->expects($this->never())->method('respond');

        try {
            $this->service()->return('contract-1', 'wrong', []);
            self::fail('token mismatch');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::INVALID_TOKEN, $e->errorCode);
        }
    }

    public function testReturnOfAnUnknownContractIsRefused(): void
    {
        $this->contracts->method('findById')->willReturn(null);

        $this->expectExceptionObject(new HeadlessCheckoutException(HeadlessCheckoutException::CONTRACT_NOT_FOUND, 'Contract contract-9 not found'));

        $this->service()->return('contract-9', 'tok', []);
    }

    public function testReturnWithoutAResolverForTheProviderIsRefused(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $contract->setProvider('paypal', 'PAY-1');
        $this->contracts->method('findById')->willReturn($contract);

        try {
            $this->service()->return('contract-1', 'tok-fixed', []);
            self::fail('no resolver registered for paypal');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::NO_RETURN_RESOLVER, $e->errorCode);
        }
    }

    public function testReturnThatDoesNotCommitReportsTheContractState(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $contract->setProvider('stripe', 'cs_1');
        $this->contracts->method('findById')->willReturn($contract);
        $this->responder->method('respond')->willReturn(null);

        $result = $this->service()->return('contract-1', 'tok-fixed', []);

        self::assertSame('pending', $result->status);
        self::assertNull($result->orderId);
        self::assertSame('pending', $result->contractState);
    }

    public function testReturnOfAnAlreadyCommittedContractIsIdempotent(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $contract->setProvider('stripe', 'cs_1');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->contracts->method('findById')->willReturn($contract);
        $this->responder->expects($this->never())->method('respond');

        $result = $this->service()->return('contract-1', 'tok-fixed', []);

        self::assertSame('committed', $result->status);
        self::assertSame('order-1', $result->orderId);
    }

    // --------------------------------------------------------------- cancel

    public function testCancelVerifiesTheTokenAndRetiresTheAttempt(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $this->contracts->method('findById')->willReturn($contract);
        $this->cleaner->expects($this->once())->method('clean')->with('contract-1')
            ->willReturnCallback(function () use ($contract): bool {
                $contract->cancel('headless cancel');

                return true;
            });

        $result = $this->service()->cancel('contract-1', 'tok-fixed');

        self::assertTrue($result->cancelled);
        self::assertSame('contract-1', $result->contractId);
        self::assertSame('cancelled', $result->contractState);
        self::assertSame('contract-1', $this->scope->getScopeId());
    }

    /**
     * Found end-to-end (PS6): PreviousCheckoutAttemptCleaner loads and cancels
     * its own copy of the contract, so the instance this service loaded for
     * the token check still said PENDING. The answer must be the repository's
     * state after the cleanup.
     */
    public function testCancelAnswersTheStateTheRepositoryHoldsAfterTheCleanup(): void
    {
        $loadedFirst = $this->pendingContract('contract-1');
        $loadedFirst->setMetadata('headless_token', 'tok-fixed');
        $afterCleanup = $this->pendingContract('contract-1');
        $afterCleanup->setMetadata('headless_token', 'tok-fixed');
        $afterCleanup->cancel('headless cancel');
        $this->contracts->method('findById')->willReturnOnConsecutiveCalls($loadedFirst, $afterCleanup);
        $this->cleaner->method('clean')->willReturn(true);

        $result = $this->service()->cancel('contract-1', 'tok-fixed');

        self::assertTrue($result->cancelled);
        self::assertSame('cancelled', $result->contractState);
    }

    public function testCancelWithAWrongTokenIsRefused(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $this->contracts->method('findById')->willReturn($contract);
        $this->cleaner->expects($this->never())->method('clean');

        $this->expectException(HeadlessCheckoutException::class);

        $this->service()->cancel('contract-1', 'nope');
    }

    public function testCancelOfASettledContractIsRefusedByTheCleanerAndReported(): void
    {
        $contract = $this->pendingContract('contract-1');
        $contract->setMetadata('headless_token', 'tok-fixed');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->contracts->method('findById')->willReturn($contract);
        $this->cleaner->method('clean')->willReturn(false);

        $result = $this->service()->cancel('contract-1', 'tok-fixed');

        self::assertFalse($result->cancelled);
        self::assertSame('committed', $result->contractState);
    }

    // -------------------------------------------------------------- helpers

    private function service(): TestableHeadlessCheckoutService
    {
        return new TestableHeadlessCheckoutService(
            new PaymentHandlerRegistry([$this->stripe]),
            new ReturnResolverRegistry(['stripe' => $this->stripeResolver]),
            $this->baskets,
            $this->policy,
            $this->scope,
            $this->contracts,
            $this->responder,
            $this->cleaner,
        );
    }

    private function startRequest(
        bool $confirmTerms = true,
        string $returnUrl = 'https://app.example.com/return',
        ?string $paymentId = null,
    ): HeadlessStartRequest {
        return new HeadlessStartRequest(
            userId: 'user-1',
            basketId: 'ub-1',
            confirmTermsAndConditions: $confirmTerms,
            returnUrl: $returnUrl,
            cancelUrl: 'https://app.example.com/cancel',
            uiMode: 'hosted',
            paymentId: $paymentId,
        );
    }

    private function pendingContract(string $id): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 116.5,
            'totalNet' => 97.9,
            'totalVat' => 18.6,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();

        return $contract;
    }
}
