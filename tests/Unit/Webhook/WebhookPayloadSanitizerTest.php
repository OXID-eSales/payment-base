<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookPayloadSanitizer;
use OxidEsales\PaymentBase\Webhook\WebhookPiiKeyProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VR-18 L2 - payment-base knows the generic PII vocabulary only; the keys of one PSP's payload
 * come from that provider's tagged WebhookPiiKeyProvider. The providers below stand in for them.
 */
#[CoversClass(WebhookPayloadSanitizer::class)]
final class WebhookPayloadSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function payloadsWithPii(): iterable
    {
        yield 'a PayPal payer' => [
            [
                'id' => 'WH-1',
                'resource' => ['id' => 'CAP-9', 'payer' => ['payer_id' => 'PAYERXYZ', 'surname' => 'Zoë Muster']],
            ],
            ['PAYERXYZ', 'Zoë Muster'],
        ];
        yield 'a Mollie consumer account' => [
            [
                'id' => 'tr_1',
                'details' => ['consumerAccount' => 'DE89370400440532013000', 'consumerBic' => 'COBADEFF'],
            ],
            ['DE89370400440532013000'],
        ];
        yield 'an e-mail under a key nobody listed' => [
            ['data' => ['object' => ['receipt_email' => 'zoe@example.test', 'buyer_mail_address' => 'zoe@example.test']]],
            ['zoe@example.test'],
        ];
        yield 'a client secret, token and password' => [
            ['client_secret' => 'pi_1_secret_x', 'cardToken' => 'tkn_x', 'api_password' => 'hunter2'],
            ['pi_1_secret_x', 'tkn_x', 'hunter2'],
        ];
        yield 'a phone and an IBAN in a list' => [
            ['events' => [['phone_number' => '+49 30 1234', 'iban' => 'DE02 1203']]],
            ['+49 30 1234', 'DE02 1203'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $mustNotSurvive
     */
    #[DataProvider('payloadsWithPii')]
    public function testPiiDoesNotSurviveSanitising(array $payload, array $mustNotSurvive): void
    {
        $sanitized = json_encode($this->sanitizer()->sanitize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach ($mustNotSurvive as $pii) {
            self::assertStringNotContainsString($pii, $sanitized);
        }
    }

    public function testTheIdsOfTheEventStayReadable(): void
    {
        $sanitized = $this->sanitizer()->sanitize([
            'id' => 'WH-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP-9', 'payer' => ['payer_id' => 'PAYERXYZ'], 'amount' => ['value' => '10.00']],
        ]);

        self::assertSame('WH-1', $sanitized['id']);
        self::assertSame('PAYMENT.CAPTURE.COMPLETED', $sanitized['event_type']);
        self::assertSame('CAP-9', $sanitized['resource']['id']);
        self::assertSame('10.00', $sanitized['resource']['amount']['value']);
        self::assertSame('[REDACTED]', $sanitized['resource']['payer']);
    }

    public function testWithoutAProviderTheProvidersKeyIsNotKnown(): void
    {
        $sanitized = (new WebhookPayloadSanitizer([]))->sanitize(['consumerAccount' => 'DE89370400440532013000']);

        self::assertSame('DE89370400440532013000', $sanitized['consumerAccount']);
    }

    public function testKeysAreMatchedCaseInsensitively(): void
    {
        $sanitized = $this->sanitizer()->sanitize(['PAYER' => 'x', 'Customer_EMAIL' => 'y']);

        self::assertSame(['PAYER' => '[REDACTED]', 'Customer_EMAIL' => '[REDACTED]'], $sanitized);
    }

    public function testAStructureTooDeepIsTruncatedNotWalkedForever(): void
    {
        $deep = ['id' => 'x'];
        for ($level = 0; $level < 20; ++$level) {
            $deep = ['level' => $deep];
        }

        $sanitized = json_encode($this->sanitizer()->sanitize($deep), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('[TRUNCATED]', $sanitized);
    }

    private function sanitizer(): WebhookPayloadSanitizer
    {
        return new WebhookPayloadSanitizer([
            $this->providerOf(['payer']),
            $this->providerOf(['consumerAccount', 'consumerBic']),
        ]);
    }

    /**
     * @param list<string> $keys
     */
    private function providerOf(array $keys): WebhookPiiKeyProvider
    {
        return new class ($keys) implements WebhookPiiKeyProvider {
            /**
             * @param list<string> $keys
             */
            public function __construct(private readonly array $keys)
            {
            }

            public function piiKeys(): array
            {
                return $this->keys;
            }
        };
    }
}
