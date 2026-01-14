<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Webhook;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepository;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookIdempotencyChecker;
use OxidEsales\PaymentComponent\Webhook\WebhookIdempotencyCheckerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookProcessor;
use OxidEsales\PaymentComponent\Webhook\WebhookProcessorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\PaymentComponent\Webhook\WebhookProcessor
 */
final class WebhookProcessorTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private EventDispatcherInterface $eventDispatcher;
    private WebhookIdempotencyCheckerInterface $idempotencyChecker;
    private WebhookLogRepositoryInterface $logRepository;
    private LoggerInterface $logger;
    private WebhookProcessorInterface $processor;
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = new ContractRepository();
        $this->eventDispatcher = new EventDispatcher();
        $this->logRepository = new WebhookLogRepository();
        $this->idempotencyChecker = new WebhookIdempotencyChecker($this->logRepository);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->dispatchedEvents = [];
        $this->eventDispatcher->addListener(
            WebhookReceivedEvent::class,
            function (WebhookReceivedEvent $event) {
                $this->dispatchedEvents[] = $event;
            }
        );

        $this->processor = new WebhookProcessor(
            $this->contractRepository,
            $this->eventDispatcher,
            $this->idempotencyChecker,
            $this->logRepository,
            $this->logger,
            'stripe'
        );
    }

    public function testProcessesPaymentSucceededWebhook(): void
    {
        $paymentIntentId = 'pi_test_123';
        $contract = $this->createContract($paymentIntentId);
        $this->contractRepository->save($contract);

        $webhookData = [
            'id' => 'evt_test_success',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'status' => 'succeeded',
                ],
            ],
        ];

        $this->processor->process($webhookData);

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertInstanceOf(WebhookReceivedEvent::class, $event);
        $this->assertSame('stripe', $event->getProvider());
        $this->assertSame('payment_intent.succeeded', $event->getEventType());
        $this->assertSame($webhookData['data'], $event->getPayload());
    }

    public function testFindsContractByProviderPaymentId(): void
    {
        $paymentIntentId = 'pi_find_me_123';
        $contract = $this->createContract($paymentIntentId);
        $this->contractRepository->save($contract);

        $webhookData = [
            'id' => 'evt_find_contract',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                ],
            ],
        ];

        $this->processor->process($webhookData);

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $context = $event->getContext();
        $this->assertSame($contract->getId(), $context->get('contractId'));
    }

    public function testSkipsDuplicateWebhooks(): void
    {
        $eventId = 'evt_duplicate_123';
        $paymentIntentId = 'pi_duplicate_123';
        $contract = $this->createContract($paymentIntentId);
        $this->contractRepository->save($contract);

        $webhookData = [
            'id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                ],
            ],
        ];

        $this->processor->process($webhookData);
        $this->assertCount(1, $this->dispatchedEvents);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('already processed'),
                $this->arrayHasKey('eventId')
            );

        $this->processor->process($webhookData);
        $this->assertCount(1, $this->dispatchedEvents);
    }

    public function testHandlesUnknownContract(): void
    {
        $webhookData = [
            'id' => 'evt_unknown_contract',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_nonexistent_123',
                ],
            ],
        ];

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Contract not found'),
                $this->arrayHasKey('paymentIntentId')
            );

        $this->processor->process($webhookData);

        $this->assertCount(0, $this->dispatchedEvents);
    }

    public function testProcessesPaymentFailedWebhook(): void
    {
        $paymentIntentId = 'pi_failed_123';
        $contract = $this->createContract($paymentIntentId);
        $this->contractRepository->save($contract);

        $webhookData = [
            'id' => 'evt_payment_failed',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'status' => 'failed',
                    'last_payment_error' => [
                        'message' => 'Card declined',
                    ],
                ],
            ],
        ];

        $this->processor->process($webhookData);

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertSame('payment_intent.payment_failed', $event->getEventType());
        $this->assertArrayHasKey('last_payment_error', $event->getPayload()['object']);
    }

    public function testProcessesRefundedWebhook(): void
    {
        $paymentIntentId = 'pi_refund_123';
        $contract = $this->createContract($paymentIntentId);
        $this->contractRepository->save($contract);

        $webhookData = [
            'id' => 'evt_refunded',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                    'refunded' => true,
                    'amount_refunded' => 1000,
                ],
            ],
        ];

        $this->processor->process($webhookData);

        $this->assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        $this->assertSame('charge.refunded', $event->getEventType());
    }

    public function testLogsAllWebhookEvents(): void
    {
        $eventId = 'evt_logging_test';
        $paymentIntentId = 'pi_logging_123';
        $contract = $this->createContract($paymentIntentId);
        $this->contractRepository->save($contract);

        $webhookData = [
            'id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $paymentIntentId,
                ],
            ],
        ];

        $this->processor->process($webhookData);

        $log = $this->logRepository->findByEventId($eventId);
        $this->assertNotNull($log);
        $this->assertSame($eventId, $log->getEventId());
        $this->assertSame('processed', $log->getStatus());
        $this->assertSame('payment_intent.succeeded', $log->getEventType());
        $this->assertSame($contract->getId(), $log->getContractId());
    }

    private function createContract(string $providerOrderId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 1000.0,
            'totalNet' => 840.0,
            'totalVat' => 160.0,
            'currency' => 'EUR',
            'capturedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user_123', $basketSnapshot);
        $contract->setProvider('stripe', $providerOrderId);

        return $contract;
    }
}
