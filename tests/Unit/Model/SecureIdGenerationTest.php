<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Model;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 47: Fix 4 - Verify CSPRNG ID generation (STRP-99).
 *
 * Tests that generated IDs use cryptographically secure random bytes
 * and fit within OXID's char(32) column constraint.
 */
class SecureIdGenerationTest extends TestCase
{
    public function testPaymentContractIdIs32Chars(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $id = $contract->getId();

        $this->assertSame(32, strlen($id));
    }

    public function testPaymentContractIdContainsHexChars(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $id = $contract->getId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testConsecutiveIdsAreDifferent(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);

        $contract1 = new PaymentContract(1, 'user123', $snapshot);
        $contract2 = new PaymentContract(1, 'user123', $snapshot);

        $this->assertNotEquals($contract1->getId(), $contract2->getId());
    }

    public function testWebhookLogIdIs32Chars(): void
    {
        $log = new WebhookLog(
            'evt_test_123',
            new \DateTimeImmutable(),
            'received'
        );

        $id = $log->getId();

        $this->assertSame(32, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function testWebhookLogConsecutiveIdsAreDifferent(): void
    {
        $log1 = new WebhookLog('evt_1', new \DateTimeImmutable(), 'received');
        $log2 = new WebhookLog('evt_2', new \DateTimeImmutable(), 'received');

        $this->assertNotEquals($log1->getId(), $log2->getId());
    }

    public function testWebhookLogPreservesProvidedId(): void
    {
        $log = new WebhookLog(
            'evt_test',
            new \DateTimeImmutable(),
            'received',
            'custom_id_123'
        );

        $this->assertEquals('custom_id_123', $log->getId());
    }
}
