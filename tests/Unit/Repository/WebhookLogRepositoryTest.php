<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use OxidEsales\PaymentComponent\Repository\WebhookLogRepository;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Repository\WebhookLogRepository
 */
final class WebhookLogRepositoryTest extends TestCase
{
    private WebhookLogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new WebhookLogRepository();
    }

    public function testSaveWebhookLog(): void
    {
        $log = new WebhookLog('evt_test_123', new \DateTimeImmutable(), 'processed');

        $this->repository->save($log);

        $found = $this->repository->findByEventId('evt_test_123');
        $this->assertSame($log, $found);
    }

    public function testFindByEventIdReturnsNullWhenNotFound(): void
    {
        $found = $this->repository->findByEventId('evt_nonexistent');

        $this->assertNull($found);
    }

    public function testExistsByEventIdReturnsTrueWhenExists(): void
    {
        $log = new WebhookLog('evt_exists', new \DateTimeImmutable(), 'processed');
        $this->repository->save($log);

        $exists = $this->repository->existsByEventId('evt_exists');

        $this->assertTrue($exists);
    }

    public function testExistsByEventIdReturnsFalseWhenNotExists(): void
    {
        $exists = $this->repository->existsByEventId('evt_nonexistent');

        $this->assertFalse($exists);
    }

    public function testSaveMultipleWebhookLogs(): void
    {
        $log1 = new WebhookLog('evt_1', new \DateTimeImmutable(), 'processed');
        $log2 = new WebhookLog('evt_2', new \DateTimeImmutable(), 'processed');

        $this->repository->save($log1);
        $this->repository->save($log2);

        $this->assertTrue($this->repository->existsByEventId('evt_1'));
        $this->assertTrue($this->repository->existsByEventId('evt_2'));
    }

    public function testFindByEventIdRetrievesCorrectLog(): void
    {
        $log1 = new WebhookLog('evt_1', new \DateTimeImmutable(), 'processed');
        $log2 = new WebhookLog('evt_2', new \DateTimeImmutable(), 'failed');

        $this->repository->save($log1);
        $this->repository->save($log2);

        $found = $this->repository->findByEventId('evt_2');

        $this->assertSame($log2, $found);
        $this->assertSame('failed', $found->getStatus());
    }
}
