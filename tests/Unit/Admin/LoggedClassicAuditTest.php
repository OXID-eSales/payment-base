<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\LoggedClassicAudit;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\PaymentBase\Payment\ClassicAdminSessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The classic admin has no audit chain of the admin API: its money actions leave one structured log entry each,
 * with the admin, the order, the field names and the amount - never the free text.
 */
final class LoggedClassicAuditTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $entries = [];

    public function testASuccessfulCallIsLoggedWithTheAdminTheOrderAndTheAmount(): void
    {
        $result = PaymentOperationResult::done(new PaymentContractDetails('c', 'paypal', 'fulfilled', 1.0, 1.0, 0.0, 'EUR', []));

        $returned = $this->audit()->audited('adminPaymentRefund', 'order-1', ['amount', 'reason'], 12.5, static fn (): PaymentOperationResult => $result);

        self::assertSame($result, $returned);
        self::assertSame('info', $this->entries[0]['level']);
        self::assertSame(
            ['admin' => 'admin-1', 'operation' => 'adminPaymentRefund', 'orderId' => 'order-1', 'fields' => ['amount', 'reason'], 'amount' => 12.5, 'outcome' => 'success'],
            $this->entries[0]['context'],
        );
    }

    public function testARefusedCallIsLoggedWithItsCode(): void
    {
        $this->audit()->audited('adminPaymentRefund', 'order-1', [], null, static fn (): PaymentOperationResult => PaymentOperationResult::refused(PaymentRefusal::invalid('amount', 'exceeds_refundable', 'm')));

        self::assertSame('exceeds_refundable', $this->entries[0]['context']['outcome']);
    }

    public function testACallThatThrowsIsLoggedAndTheExceptionPropagates(): void
    {
        try {
            $this->audit()->audited('adminPaymentRefund', 'order-1', [], null, static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('the exception must propagate');
        } catch (RuntimeException) {
        }

        self::assertSame('warning', $this->entries[0]['level']);
        self::assertSame('threw:' . RuntimeException::class, $this->entries[0]['context']['outcome']);
    }

    private function audit(): LoggedClassicAudit
    {
        $session = $this->createStub(ClassicAdminSessionInterface::class);
        $session->method('userId')->willReturn('admin-1');
        $logger = new class ($this) extends AbstractLogger {
            public function __construct(private readonly LoggedClassicAuditTest $test)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->test->logged((string) $level, (string) $message, $context);
            }
        };

        return new LoggedClassicAudit($session, $logger);
    }

    /**
     * @param array<mixed> $context
     *
     * @internal for the logger double
     */
    public function logged(string $level, string $message, array $context): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
