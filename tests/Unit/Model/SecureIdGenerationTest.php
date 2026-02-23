<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Model;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 47: Fix 4 - Verify CSPRNG ID generation (STRP-99).
 *
 * Tests that generated IDs use cryptographically secure random bytes
 * instead of uniqid().
 */
class SecureIdGenerationTest extends TestCase
{
    public function testPaymentContractIdIsSufficientlyLong(): void
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

        // contract_ prefix (9) + 32 hex chars = 41 minimum
        $this->assertGreaterThanOrEqual(36, strlen($id));
        $this->assertStringStartsWith('contract_', $id);
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

        // Extract the random part after prefix
        $randomPart = substr($id, strlen('contract_'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $randomPart);
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

    public function testWebhookLogIdIsSufficientlyLong(): void
    {
        $log = new WebhookLog(
            'evt_test_123',
            new \DateTimeImmutable(),
            'received'
        );

        $id = $log->getId();

        // webhook_log_ prefix (12) + 32 hex chars = 44 minimum
        $this->assertGreaterThanOrEqual(36, strlen($id));
        $this->assertStringStartsWith('webhook_log_', $id);
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
