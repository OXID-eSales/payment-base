<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\RecordingSessionAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reported bug: retrying a checkout in one session left a NOT_FINISHED
 * order behind per attempt, so the backend filled with consecutive orders
 * seconds apart and no payment date. The order is created here, before the
 * shopper leaves for the PSP, so this is where the previous attempt has to be
 * retired.
 */
final class EarlyOrderCreationHandlerRetryCleanupTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $shopOrderService;
    private PreviousCheckoutAttemptCleanerInterface&MockObject $cleaner;
    private RecordingSessionAdapter $session;
    private OpenCheckoutAttemptRegistry $openAttempts;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->cleaner = $this->createMock(PreviousCheckoutAttemptCleanerInterface::class);
        $this->session = new RecordingSessionAdapter();
        $this->openAttempts = new OpenCheckoutAttemptRegistry($this->session);

        $this->shopOrderService->method('createOrder')->willReturn($this->orderResponse());
    }

    private function handler(): EarlyOrderCreationHandler
    {
        return new EarlyOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->createMock(EventDispatcherInterface::class),
            null,
            $this->cleaner,
            $this->openAttempts
        );
    }

    private function draftContract(string $id): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user123', $snapshot, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        return $contract;
    }

    private function orderResponse(): OrderResponse
    {
        return new OrderResponse(
            orderId: 'order-new',
            orderNumber: 1001,
            userId: 'user123',
            totalAmount: 100.0,
            currency: 'EUR',
            status: 'not_finished',
            paymentId: 'oe_payments_mollie',
            paymentTransactionId: null,
            createdAt: new DateTimeImmutable(),
            metadata: [],
            shopData: []
        );
    }

    private function handle(string $contractId): void
    {
        $this->handler()->handle(
            new ContractDraftCompletedEvent(
                $this->draftContract($contractId),
                new EventContext(['paymentId' => 'oe_payments_mollie'])
            )
        );
    }

    public function testRetiresTheAttemptThisSessionAlreadyHadOpen(): void
    {
        $this->openAttempts->remember('contract-first');

        $this->cleaner->expects($this->once())->method('clean')->with('contract-first');

        $this->handle('contract-second');
    }

    public function testRemembersTheNewAttemptSoTheNextRetryCanRetireIt(): void
    {
        $this->handle('contract-second');

        $this->assertSame(
            'contract-second',
            $this->session->getVariable(OpenCheckoutAttemptRegistry::SESSION_KEY)
        );
    }

    public function testCleansNothingOnAFirstAttempt(): void
    {
        $this->cleaner->expects($this->never())->method('clean');

        $this->handle('contract-first');
    }

    public function testNeverRetiresTheAttemptItIsCreatingRightNow(): void
    {
        $this->openAttempts->remember('contract-same');

        $this->cleaner->expects($this->never())->method('clean');

        $this->handle('contract-same');
    }

    public function testAFailedCleanupStillLetsTheShopperCheckOut(): void
    {
        // Losing a stale order is annoying; refusing the new checkout is worse.
        $this->openAttempts->remember('contract-first');
        $this->cleaner->method('clean')->willThrowException(new \RuntimeException('db gone'));

        $this->handle('contract-second');

        $this->assertSame(
            'contract-second',
            $this->session->getVariable(OpenCheckoutAttemptRegistry::SESSION_KEY),
            'the new attempt must still be recorded'
        );
    }

    public function testWithoutTheCollaboratorsItBehavesExactlyAsBefore(): void
    {
        // The arguments are optional so that a consumer whose services.yaml
        // predates this fix keeps working, just without the cleanup.
        $handler = new EarlyOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->createMock(EventDispatcherInterface::class)
        );

        $contract = $this->draftContract('contract-only');
        $handler->handle(new ContractDraftCompletedEvent($contract, new EventContext([])));

        $this->assertTrue($contract->getState()->isPending());
    }
}
