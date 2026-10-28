<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Controller;

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

        $answer = (new PaymentQueries($reader))->adminPaymentContractStateHelp('stripe', 'de');

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

        $answer = (new PaymentQueries($reader))->adminPaymentContractStateHelp();

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
}
