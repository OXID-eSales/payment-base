<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Service\WebhookLogService;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the optional $shouldLogPayload closure seam on WebhookLogService.
 *
 * Phase 4 (logging-control sprint): payload write and PSR-3 mirror can be
 * suppressed by injecting a closure that returns false, without affecting the
 * idempotency/claim row written by the repository layer.
 */
#[CoversClass(\OxidEsales\PaymentBase\Service\WebhookLogService::class)]
final class WebhookLogServicePayloadGateTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    // -------------------------------------------------------------------------
    // Default (null gate) → back-compat: payload persisted + PSR-3 emitted
    // -------------------------------------------------------------------------

    public function testNullGatePreservesPayloadAndEmitsPsr3(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger);

        $payload = ['id' => 'pi_test', 'amount' => 1000];

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use ($payload): bool {
                return $log->getPayload() === $payload;
            }));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Webhook event received', $this->anything());

        $result = $service->logEventReceived('evt_001', 'payment_intent.succeeded', $payload, 'stripe');

        $this->assertSame($payload, $result->getPayload());
    }

    public function testNullGateMarkProcessedEmitsPsr3(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger);

        $this->repository->method('updateStatus');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Webhook event processed', $this->anything());

        $service->markEventProcessed('evt_001');
    }

    public function testNullGateMarkFailedEmitsPsr3(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger);

        $this->repository->method('updateStatus');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Webhook event failed', $this->anything());

        $service->markEventFailed('evt_001', 'some error');
    }

    // -------------------------------------------------------------------------
    // Gate returns true → same as null: payload + PSR-3 emitted
    // -------------------------------------------------------------------------

    public function testGateTruePreservesPayloadAndEmitsPsr3(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => true);

        $payload = ['id' => 'pi_test'];

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use ($payload): bool {
                return $log->getPayload() === $payload;
            }));

        $this->logger->expects($this->once())
            ->method('info');

        $service->logEventReceived('evt_002', 'payment_intent.succeeded', $payload, 'stripe');
    }

    // -------------------------------------------------------------------------
    // Gate returns false → claim row still saved, OXPAYLOAD omitted, no PSR-3
    // -------------------------------------------------------------------------

    public function testGateFalseOmitsPayloadFromSavedLog(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $savedLog = null;
        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog): bool {
                $savedLog = $log;
                return true;
            }));

        $service->logEventReceived('evt_003', 'payment_intent.succeeded', ['secret' => 'data'], 'stripe');

        $this->assertNotNull($savedLog);
        $this->assertNull($savedLog->getPayload(), 'OXPAYLOAD must be null/empty when gate returns false');
    }

    public function testGateFalseRowStillHasEventIdAndType(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $savedLog = null;
        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog): bool {
                $savedLog = $log;
                return true;
            }));

        $service->logEventReceived('evt_004', 'checkout.session.completed', ['data' => 'x'], 'stripe');

        $this->assertNotNull($savedLog);
        $this->assertSame('evt_004', $savedLog->getEventId());
        $this->assertSame('checkout.session.completed', $savedLog->getEventType());
        $this->assertSame(WebhookLogService::STATUS_RECEIVED, $savedLog->getStatus());
    }

    public function testGateFalseSupressesPsr3OnLogEventReceived(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $this->repository->method('save');

        // Logger must NOT be called with 'info' for the webhook-received mirror
        $this->logger->expects($this->never())
            ->method('info');

        $service->logEventReceived('evt_005', 'payment_intent.succeeded', ['amount' => 500], 'stripe');
    }

    public function testGateFalseSupressesPsr3OnMarkEventProcessed(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $this->repository->method('updateStatus');

        $this->logger->expects($this->never())
            ->method('info');

        $service->markEventProcessed('evt_006');
    }

    public function testGateFalseSupressesPsr3OnMarkEventFailed(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $this->repository->method('updateStatus');

        $this->logger->expects($this->never())
            ->method('warning');

        $service->markEventFailed('evt_007', 'processing error');
    }

    // -------------------------------------------------------------------------
    // Idempotency: with gate=false the claim row still enables dedup
    // -------------------------------------------------------------------------

    /**
     * Proves idempotency via existsByEventId() still works when payload logging is OFF.
     *
     * The claim row (written by repository->claimEvent()) never carries OXPAYLOAD,
     * so existsByEventId() returns true for a 2nd call regardless of the gate state.
     */
    public function testIdempotencyStillWorksWithGateFalse(): void
    {
        // First call: gate=false, repository returns true for existsByEventId
        // (simulating that claimEvent already inserted a row)
        $this->repository->expects($this->once())
            ->method('existsByEventId')
            ->with('evt_idempotency')
            ->willReturn(true);

        $serviceWithGateOff = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $exists = $serviceWithGateOff->eventExists('evt_idempotency');

        $this->assertTrue($exists, 'eventExists() must return true even when payload logging is disabled');
    }

    /**
     * Proves the save() is still called (row is written) when gate=false,
     * ensuring that a second claimEvent() for the same OXEVENTID would violate
     * the unique constraint and be rejected by the repository.
     *
     * The service-layer test focuses on: save() is called with a log that has
     * the event id, so the repository can enforce its unique constraint.
     */
    public function testSaveIsCalledEvenWithGateFalse(): void
    {
        $service = new WebhookLogService($this->repository, $this->logger, fn () => false);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log): bool {
                // Row must carry OXEVENTID so the repo can deduplicate
                return $log->getEventId() === 'evt_dedup_test';
            }));

        $service->logEventReceived('evt_dedup_test', 'payment_intent.succeeded', [], 'stripe');
    }
}
