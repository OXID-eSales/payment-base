<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IdempotencyRecord entity.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @covers \OxidEsales\PaymentBase\Contract\IdempotencyRecord
 * @group sprint-42
 * @group idempotency
 */
class IdempotencyRecordTest extends TestCase
{
    /**
     * @test
     */
    public function constructSetsRequiredFields(): void
    {
        $createdAt = new DateTimeImmutable('2026-02-06 10:00:00');
        $expiresAt = new DateTimeImmutable('2026-02-07 10:00:00');

        $record = new IdempotencyRecord(
            'id_123',
            'capture:pi_abc',
            'pi_abc',
            'capture',
            'processing',
            $createdAt,
            $expiresAt
        );

        $this->assertSame('id_123', $record->getId());
        $this->assertSame('capture:pi_abc', $record->getKey());
        $this->assertSame('pi_abc', $record->getOrderId());
        $this->assertSame('capture', $record->getOperation());
        $this->assertSame('processing', $record->getStatus());
        $this->assertNull($record->getResult());
        $this->assertSame($createdAt, $record->getCreatedAt());
        $this->assertSame($expiresAt, $record->getExpiresAt());
    }

    /**
     * @test
     */
    public function setResultUpdatesValue(): void
    {
        $record = $this->createRecord();
        $this->assertNull($record->getResult());

        $record->setResult('{"success":true}');
        $this->assertSame('{"success":true}', $record->getResult());
    }

    /**
     * @test
     */
    public function setStatusUpdatesValue(): void
    {
        $record = $this->createRecord();
        $this->assertSame('processing', $record->getStatus());

        $record->setStatus('completed');
        $this->assertSame('completed', $record->getStatus());
    }

    /**
     * @test
     */
    public function isExpiredReturnsTrueWhenPastExpiry(): void
    {
        $record = new IdempotencyRecord(
            'id_123',
            'capture:pi_abc',
            'pi_abc',
            'capture',
            'completed',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day')
        );

        $this->assertTrue($record->isExpired());
    }

    /**
     * @test
     */
    public function isExpiredReturnsFalseWhenNotExpired(): void
    {
        $record = new IdempotencyRecord(
            'id_123',
            'capture:pi_abc',
            'pi_abc',
            'capture',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );

        $this->assertFalse($record->isExpired());
    }

    /**
     * @test
     */
    public function toArrayReturnsAllFields(): void
    {
        $createdAt = new DateTimeImmutable('2026-02-06 10:00:00');
        $expiresAt = new DateTimeImmutable('2026-02-07 10:00:00');

        $record = new IdempotencyRecord(
            'id_123',
            'capture:pi_abc',
            'pi_abc',
            'capture',
            'completed',
            $createdAt,
            $expiresAt
        );
        $record->setResult('{"success":true}');

        $array = $record->toArray();

        $this->assertSame('id_123', $array['id']);
        $this->assertSame('capture:pi_abc', $array['key']);
        $this->assertSame('pi_abc', $array['orderId']);
        $this->assertSame('capture', $array['operation']);
        $this->assertSame('{"success":true}', $array['result']);
        $this->assertSame('completed', $array['status']);
        $this->assertSame('2026-02-06 10:00:00', $array['createdAt']);
        $this->assertSame('2026-02-07 10:00:00', $array['expiresAt']);
    }

    /**
     * @test
     */
    public function fromArrayHydratesAllFields(): void
    {
        $data = [
            'id' => 'id_456',
            'key' => 'refund:pi_xyz',
            'orderId' => 'pi_xyz',
            'operation' => 'refund',
            'result' => '{"refundId":"re_123"}',
            'status' => 'completed',
            'createdAt' => '2026-02-06 10:00:00',
            'expiresAt' => '2026-02-07 10:00:00',
        ];

        $record = IdempotencyRecord::fromArray($data);

        $this->assertSame('id_456', $record->getId());
        $this->assertSame('refund:pi_xyz', $record->getKey());
        $this->assertSame('pi_xyz', $record->getOrderId());
        $this->assertSame('refund', $record->getOperation());
        $this->assertSame('{"refundId":"re_123"}', $record->getResult());
        $this->assertSame('completed', $record->getStatus());
        $this->assertSame('2026-02-06 10:00:00', $record->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-07 10:00:00', $record->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    /**
     * @test
     */
    public function fromArrayWithoutResultSetsNull(): void
    {
        $data = [
            'id' => 'id_789',
            'key' => 'capture:pi_new',
            'orderId' => 'pi_new',
            'operation' => 'capture',
            'status' => 'processing',
            'createdAt' => '2026-02-06 10:00:00',
            'expiresAt' => '2026-02-07 10:00:00',
        ];

        $record = IdempotencyRecord::fromArray($data);

        $this->assertNull($record->getResult());
    }

    /**
     * @test
     */
    public function toArrayFromArrayRoundTrip(): void
    {
        $record = $this->createRecord();
        $record->setResult('{"test":true}');
        $record->setStatus('completed');

        $array = $record->toArray();
        $restored = IdempotencyRecord::fromArray($array);

        $this->assertSame($record->getId(), $restored->getId());
        $this->assertSame($record->getKey(), $restored->getKey());
        $this->assertSame($record->getOrderId(), $restored->getOrderId());
        $this->assertSame($record->getOperation(), $restored->getOperation());
        $this->assertSame($record->getResult(), $restored->getResult());
        $this->assertSame($record->getStatus(), $restored->getStatus());
    }

    private function createRecord(): IdempotencyRecord
    {
        return new IdempotencyRecord(
            'id_123',
            'capture:pi_abc',
            'pi_abc',
            'capture',
            'processing',
            new DateTimeImmutable('2026-02-06 10:00:00'),
            new DateTimeImmutable('2026-02-07 10:00:00')
        );
    }
}
