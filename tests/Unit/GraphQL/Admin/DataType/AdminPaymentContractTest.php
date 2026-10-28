<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\DataType;

use DateTimeImmutable;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentTransaction;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentTransactionDetails;
use PHPUnit\Framework\TestCase;

final class AdminPaymentContractTest extends TestCase
{
    public function testFields_MirrorTheReadModel_WithTheStateInTheLifecycleSpelling(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-26 09:00:00');
        $details = new PaymentContractDetails(
            'c-1',
            'paypal',
            'ready_to_commit',
            100.0,
            100.0,
            25.0,
            'EUR',
            [new PaymentTransactionDetails('t-1', 'refund', 'completed', 25.0, 'EUR', $createdAt, 'REF-1')],
        );

        $contract = new AdminPaymentContract($details);

        self::assertSame('c-1', $contract->contractId());
        self::assertSame('paypal', $contract->provider());
        self::assertSame('READY_TO_COMMIT', $contract->state());
        self::assertSame(100.0, $contract->amountAuthorised());
        self::assertSame(100.0, $contract->amountCaptured());
        self::assertSame(25.0, $contract->amountRefunded());
        self::assertSame('EUR', $contract->currency());
        $transactions = $contract->transactions();
        self::assertCount(1, $transactions);
        self::assertInstanceOf(AdminPaymentTransaction::class, $transactions[0]);
        self::assertSame(['t-1', 'refund', 'completed', 25.0, 'EUR', $createdAt, 'REF-1'], [
            $transactions[0]->id(),
            $transactions[0]->type(),
            $transactions[0]->status(),
            $transactions[0]->amount(),
            $transactions[0]->currency(),
            $transactions[0]->createdAt(),
            $transactions[0]->providerReference(),
        ]);
    }

    public function testBounds_AreExposedSoTheScreenNeverRecomputesThem(): void
    {
        $authorised = new AdminPaymentContract(new PaymentContractDetails('c', 'stripe', 'authorized', 80.0, 0.0, 0.0, 'EUR', []));
        $captured = new AdminPaymentContract(new PaymentContractDetails('c', 'stripe', 'fulfilled', 80.0, 80.0, 30.0, 'EUR', []));

        self::assertSame([80.0, 0.0], [$authorised->amountCapturable(), $authorised->amountRefundable()]);
        self::assertSame([0.0, 50.0], [$captured->amountCapturable(), $captured->amountRefundable()]);
    }

    public function testTheTypeCarriesNoProviderPayloadOrSecret(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(AdminPaymentContract::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $exposed = array_diff($methods, ['__construct']);
        sort($exposed);

        self::assertSame(
            ['amountAuthorised', 'amountCapturable', 'amountCaptured', 'amountRefundable', 'amountRefunded', 'contractId', 'currency', 'provider', 'state', 'transactions'],
            $exposed,
        );
    }
}
