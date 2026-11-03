<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Controller;

use DateTimeImmutable;
use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRisk;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskQueryInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Controller\PaymentQueries;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelp;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelpReaderInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelpRow;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class PaymentQueriesTest extends TestCase
{
    public function testAdminPaymentContractStateHelp_DelegatesWithProviderAndLanguage(): void
    {
        $reader = $this->createMock(AdminStateHelpReaderInterface::class);
        $reader->expects(self::once())->method('read')->with('stripe', 'de')
            ->willReturn(new AdminStateHelp('Stripe', [new AdminStateHelpRow('AUTHORIZED', ['AUTHORIZED'], 'meaning', 'requires_capture')]));

        $answer = (new PaymentQueries($reader, $this->createStub(PaymentAtRiskQueryInterface::class)))->adminPaymentContractStateHelp('stripe', 'de');

        self::assertSame('Stripe', $answer->providerLabel());
        self::assertCount(1, $answer->rows());
        self::assertSame(['AUTHORIZED', ['AUTHORIZED'], 'meaning', 'requires_capture'], [
            $answer->rows()[0]->state(),
            $answer->rows()[0]->states(),
            $answer->rows()[0]->meaning(),
            $answer->rows()[0]->providerStatus(),
        ]);
    }

    public function testAdminPaymentContractStateHelp_DefaultsToEnglishWithoutProvider(): void
    {
        $reader = $this->createMock(AdminStateHelpReaderInterface::class);
        $reader->expects(self::once())->method('read')->with(null, 'en')->willReturn(new AdminStateHelp(null, []));

        $answer = (new PaymentQueries($reader, $this->createStub(PaymentAtRiskQueryInterface::class)))->adminPaymentContractStateHelp();

        self::assertNull($answer->providerLabel());
        self::assertSame([], $answer->rows());
    }

    public function testTheQueryNeedsTheViewRight_AndNoLoggedAttribute(): void
    {
        $method = new ReflectionMethod(PaymentQueries::class, 'adminPaymentContractStateHelp');

        self::assertCount(1, $method->getAttributes(Query::class));
        self::assertSame([PaymentRightsProvider::VIEW], $method->getAttributes(Right::class)[0]->getArguments());
        self::assertCount(0, $method->getAttributes(Logged::class), 'a refusal must carry an errorCode, like every admin operation');
    }

    /**
     * VR-23 T1: the order-list filter. The graphql-admin order query filters order columns only, so the screen
     * asks for the orders whose payment is at risk here and narrows its list to them.
     */
    public function testAdminPaymentsAtRisk_ListsOpenFlagsOfOrders(): void
    {
        $raisedAt = new DateTimeImmutable('2026-11-03 02:00:00');
        $atRisk = $this->createMock(PaymentAtRiskQueryInterface::class);
        $atRisk->expects(self::once())->method('openForOrders')->with(50)->willReturn([
            new PaymentAtRisk('f-1', 'c-1', 'order-1', AtRiskReason::CaptureReversed, 'cap_1', $raisedAt),
        ]);

        $flags = (new PaymentQueries($this->createStub(AdminStateHelpReaderInterface::class), $atRisk))->adminPaymentsAtRisk(50);

        self::assertCount(1, $flags);
        self::assertSame(['order-1', 'CAPTURE_REVERSED'], [$flags[0]->orderId(), $flags[0]->reason()]);
    }

    public function testAdminPaymentsAtRisk_AsksForAHundredByDefaultAndNeedsTheViewRight(): void
    {
        $atRisk = $this->createMock(PaymentAtRiskQueryInterface::class);
        $atRisk->expects(self::once())->method('openForOrders')->with(100)->willReturn([]);

        (new PaymentQueries($this->createStub(AdminStateHelpReaderInterface::class), $atRisk))->adminPaymentsAtRisk();
        $method = new ReflectionMethod(PaymentQueries::class, 'adminPaymentsAtRisk');

        self::assertCount(1, $method->getAttributes(Query::class));
        self::assertSame([PaymentRightsProvider::VIEW], $method->getAttributes(Right::class)[0]->getArguments());
    }
}
