<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperations;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use OxidEsales\PaymentBase\Payment\MoneyOperationRunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The admin API hands capture, refund and void to the one money path, through its own door and nothing else
 * (what the path decides is MoneyOperationRunnerTest's).
 */
final class AdminPaymentOperationsTest extends TestCase
{
    /** @var list<array{AdminPaymentOperation, AdminChannelInterface}> */
    private array $runs = [];

    public function testCapture_IsHandedToThePathThroughTheAdminApisChannel(): void
    {
        $result = $this->operations()->capture('order-1', 40.0, 'key-capture-1', 'shipped');

        [$operation, $channel] = $this->runs[0];
        self::assertSame(AdminPaymentOperation::CAPTURE, $operation->kind);
        self::assertSame(['order-1', 40.0, 'key-capture-1', 'shipped'], [$operation->orderId, $operation->amount, $operation->clientKey, $operation->reason]);
        self::assertSame($this->channel, $channel);
        self::assertNull($result->refusal());
    }

    public function testRefund_IsHandedToThePath_WithItsReasonAndDescription(): void
    {
        $this->operations()->refund('order-1', 12.5, 'duplicate', 'key-refund-1', 'Twice charged');

        $operation = $this->runs[0][0];
        self::assertSame(AdminPaymentOperation::REFUND, $operation->kind);
        self::assertSame([12.5, 'duplicate', 'Twice charged'], [$operation->amount, $operation->reason, $operation->description]);
    }

    public function testVoid_IsHandedToThePath(): void
    {
        $this->operations()->void('order-1', 'key-void-1', 'out of stock');

        $operation = $this->runs[0][0];
        self::assertSame(AdminPaymentOperation::VOID, $operation->kind);
        self::assertSame('out of stock', $operation->reason);
    }

    private AdminChannelInterface $channel;

    private function operations(): AdminPaymentOperations
    {
        $this->channel = $this->createStub(AdminChannelInterface::class);
        $runner = new class ($this) implements MoneyOperationRunnerInterface {
            public function __construct(private readonly AdminPaymentOperationsTest $test)
            {
            }

            public function run(AdminPaymentOperation $operation, AdminChannelInterface $channel): PaymentOperationResult
            {
                $this->test->ran($operation, $channel);

                return PaymentOperationResult::done(new PaymentContractDetails('c', 'paypal', 'fulfilled', 1.0, 1.0, 0.0, 'EUR', []));
            }
        };

        return new AdminPaymentOperations($runner, $this->channel);
    }

    /** @internal for the runner double */
    public function ran(AdminPaymentOperation $operation, AdminChannelInterface $channel): void
    {
        $this->runs[] = [$operation, $channel];
    }
}
