<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookDeliveryLogRecord;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookDeliveryLogRecord::class)]
final class WebhookDeliveryLogRecordTest extends TestCase
{
    public function testItIsTheOneShapeOfAWebhookLogLine(): void
    {
        $record = new WebhookDeliveryLogRecord(
            provider: 'mollie',
            eventId: 'tr_1',
            eventType: 'payment.paid',
            objectId: 'tr_1',
            contractId: 'c-1',
            action: 'skipped',
            reason: 'contract_not_found',
            httpCode: 200,
            durationMs: 12,
        );

        self::assertSame([
            'provider' => 'mollie',
            'eventId' => 'tr_1',
            'eventType' => 'payment.paid',
            'objectId' => 'tr_1',
            'contractId' => 'c-1',
            'action' => 'skipped',
            'reason' => 'contract_not_found',
            'httpCode' => 200,
            'durationMs' => 12,
        ], $record->toContext());
    }

    public function testADeliveryWithoutIdsOrReasonKeepsTheKeysAsNull(): void
    {
        $record = new WebhookDeliveryLogRecord(
            provider: 'paypal',
            eventId: null,
            eventType: null,
            objectId: null,
            contractId: null,
            action: 'webhook_rejected',
            reason: 'missing_id',
            httpCode: 400,
            durationMs: 1,
        );

        $context = $record->toContext();

        self::assertNull($context['eventId']);
        self::assertSame('missing_id', $context['reason']);
        self::assertCount(9, $context);
    }

    public function testItIsBuiltFromTheResultTheProcessorReturned(): void
    {
        $record = WebhookDeliveryLogRecord::fromResult(
            provider: 'stripe',
            eventId: 'evt_1',
            eventType: 'payment_intent.canceled',
            objectId: 'pi_1',
            contractId: 'c-1',
            result: WebhookResult::skipped('contract_not_pending'),
            httpCode: 200,
            durationMs: 5,
        );

        self::assertSame('skipped', $record->action);
        self::assertSame('contract_not_pending', $record->reason);
    }

    public function testAFailureResultCarriesItsErrorAsTheReason(): void
    {
        $record = WebhookDeliveryLogRecord::fromResult('stripe', 'evt_1', 'x', null, null, WebhookResult::failure('processing_failed', 'Webhook processing failed'), 500, 7);

        self::assertSame('processing_failed', $record->action);
        self::assertSame('Webhook processing failed', $record->reason);
    }

    public function testASuccessfulActionHasNoReason(): void
    {
        $record = WebhookDeliveryLogRecord::fromResult('stripe', 'evt_1', 'x', null, 'c-1', WebhookResult::success('fulfilled'), 200, 7);

        self::assertNull($record->reason);
    }
}
