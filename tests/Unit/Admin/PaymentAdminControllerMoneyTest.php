<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Admin\AdminRefusalFeedbackInterface;
use OxidEsales\PaymentBase\Admin\ClassicAdminChannel;
use OxidEsales\PaymentBase\Admin\ClassicMoneyActionAdapter;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\PaymentAdminActionDispatcher;
use OxidEsales\PaymentBase\Admin\PaymentAdminController;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\RequestShopOrdersInterface;
use OxidEsales\PaymentBase\Payment\AdminRightCheckInterface;
use OxidEsales\PaymentBase\Payment\CapturePolicy;
use OxidEsales\PaymentBase\Payment\CaptureOperation;
use OxidEsales\PaymentBase\Payment\ClassicAdminSessionInterface;
use OxidEsales\PaymentBase\Payment\MoneyOperationRunner;
use OxidEsales\PaymentBase\Payment\ProviderGatewayRegistry;
use OxidEsales\PaymentBase\Payment\RefundOperation;
use OxidEsales\PaymentBase\Payment\RefundPolicy;
use OxidEsales\PaymentBase\Payment\SecondFactorPolicyInterface;
use OxidEsales\PaymentBase\Payment\VoidOperation;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryIdempotency;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\RecordingAudit;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\ControllableOrderLock;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\FakeGateway;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\InMemoryCaptureLedger;
use OxidEsales\PaymentBase\Tests\Unit\Payment\Support\InMemoryRefundLedger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * VR-22 A3 (S10) - the classic Payment tab's money actions go through the one money path, with the protection the
 * admin API has: the payment right, the step-up where the shop asks for a second factor, the audit, the dedupe.
 */
final class PaymentAdminControllerMoneyTest extends TestCase
{
    private InMemoryContracts $contracts;
    private FakeGateway $gateway;
    private RecordingAudit $audit;
    private InMemoryIdempotency $idempotencyRows;

    /** @var list<string> */
    private array $shown = [];

    /** @var list<string> */
    private array $askedRights = [];

    /** @var list<string> */
    private array $providerActions = [];

    private bool $holdsRight = true;
    private bool $secondFactorRequired = false;

    protected function setUp(): void
    {
        $this->contracts = new InMemoryContracts();
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal', 100.0));
        $this->gateway = new FakeGateway('paypal');
        $this->audit = new RecordingAudit();
        $this->idempotencyRows = new InMemoryIdempotency();
    }

    public function testCaptureRequiresPaymentRight(): void
    {
        $this->holdsRight = false;

        $this->controller()->dispatchAction();

        self::assertSame([PaymentRightsProvider::CAPTURE], $this->askedRights);
        self::assertSame([], $this->gateway->requests, 'no right, no capture');
        self::assertCount(1, $this->shown, 'and the admin is told');
    }

    public function testCaptureWithTheRightGoesThroughTheGateway(): void
    {
        $this->controller()->dispatchAction();

        self::assertCount(1, $this->gateway->requests);
        self::assertSame([], $this->shown);
        self::assertSame([], $this->providerActions, 'the provider panel never sees the money action');
    }

    public function testRequiresStepUpWhenMfaEnabled(): void
    {
        $this->secondFactorRequired = true;

        $this->controller()->dispatchAction();

        self::assertSame([], $this->gateway->requests);
        self::assertCount(1, $this->shown);
        self::assertStringContainsString('second factor', $this->shown[0]);
    }

    public function testIsAudited(): void
    {
        $this->controller()->dispatchAction();

        self::assertSame('adminPaymentCapture', $this->audit->rows[0]['operation']);
        self::assertSame('order-1', $this->audit->rows[0]['orderId']);
        self::assertSame('success', $this->audit->rows[0]['outcome']);
    }

    public function testADeniedAttemptIsAuditedToo(): void
    {
        $this->holdsRight = false;

        $this->controller()->dispatchAction();

        self::assertCount(1, $this->audit->rows);
        self::assertStringStartsWith('threw:', $this->audit->rows[0]['outcome']);
    }

    public function testDoubleSubmitIsDeduped(): void
    {
        $controller = $this->controller(['capture_amount' => '10', 'idempotency_key' => 'form-key-0001']);

        $controller->dispatchAction();
        $controller->dispatchAction();

        self::assertCount(1, $this->gateway->requests, 'the second click is the same call');
    }

    public function testOtherActionsStillGoToTheProvidersPanel(): void
    {
        $this->controller([], 'resync')->dispatchAction();

        self::assertSame(['resync'], $this->providerActions);
        self::assertSame([], $this->gateway->requests);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function controller(array $form = [], string $action = 'capture'): TestableMoneyController
    {
        $rights = $this->createStub(AdminRightCheckInterface::class);
        $rights->method('allows')->willReturnCallback(function (string $userId, int $shopId, string $right): bool {
            $this->askedRights[] = $right;

            return $this->holdsRight;
        });
        $session = $this->createStub(ClassicAdminSessionInterface::class);
        $session->method('userId')->willReturn('admin-1');
        $session->method('shopId')->willReturn(1);
        $secondFactor = $this->createStub(SecondFactorPolicyInterface::class);
        $secondFactor->method('isRequired')->willReturnCallback(fn (): bool => $this->secondFactorRequired);
        $feedback = new class ($this) implements AdminRefusalFeedbackInterface {
            public function __construct(private readonly PaymentAdminControllerMoneyTest $test)
            {
            }

            public function show(AdminActionRefusedException $refusal): void
            {
                $this->test->shows($refusal->getMessage());
            }
        };
        $adapter = new ClassicMoneyActionAdapter($this->runner(), new ClassicAdminChannel($session, $rights, $secondFactor, $this->audit), $feedback);

        $panel = $this->createStub(PaymentPanelProviderInterface::class);
        $panel->method('handleAction')->willReturnCallback(function (string $action): void {
            $this->providerActions[] = $action;
        });
        $registry = $this->createStub(PaymentPanelRegistryInterface::class);
        $registry->method('resolveFor')->willReturn($panel);

        return new TestableMoneyController($this->contracts, new PaymentAdminActionDispatcher($registry), $adapter, $action, $form);
    }

    private function runner(): MoneyOperationRunner
    {
        $now = new DateTimeImmutable('2026-11-03 10:00:00');
        $gateways = new ProviderGatewayRegistry([$this->gateway]);
        $capturePolicy = new CapturePolicy();
        $logger = new NullLogger();
        $shop = new class () implements RequestShopOrdersInterface {
            public function contains(string $orderId): bool
            {
                return true;
            }
        };

        return new MoneyOperationRunner(
            $this->contracts,
            new PaymentContractDetailsReader($this->contracts, $this->createStub(TransactionRepositoryInterface::class)),
            $shop,
            new ControllableOrderLock(),
            new OperationIdempotency($this->idempotencyRows, static fn (): DateTimeImmutable => $now),
            [
                new CaptureOperation($capturePolicy, $gateways, new InMemoryCaptureLedger($this->contracts), $logger),
                new RefundOperation(new RefundPolicy(), $gateways, new InMemoryRefundLedger($this->contracts), $logger),
                new VoidOperation($capturePolicy, $gateways, $logger),
            ],
            $logger,
        );
    }

    /** @internal for the feedback double */
    public function shows(string $message): void
    {
        $this->shown[] = $message;
    }
}

/**
 * The controller without OXID's admin bootstrap: the order, the CSRF check and the request are fixed.
 */
final class TestableMoneyController extends PaymentAdminController
{
    /**
     * @param array<string, mixed> $form
     */
    public function __construct(
        InMemoryContracts $contracts,
        PaymentAdminActionDispatcher $dispatcher,
        ClassicMoneyActionAdapter $moneyActions,
        private readonly string $action,
        private readonly array $form,
    ) {
        $this->contractRepository = $contracts;
        $this->actionDispatcher = $dispatcher;
        $this->moneyActions = $moneyActions;
    }

    protected function validateCsrfToken(): bool
    {
        return true;
    }

    protected function readStringParam(string $name): ?string
    {
        return $name === 'payment_admin_action' ? $this->action : null;
    }

    protected function collectActionRequest(): array
    {
        return $this->form;
    }

    protected function buildPanelContext(): ?PaymentPanelContext
    {
        return new PaymentPanelContext('order-1', 'paypal', $this->contractRepository?->findByOrderId('order-1'));
    }
}
