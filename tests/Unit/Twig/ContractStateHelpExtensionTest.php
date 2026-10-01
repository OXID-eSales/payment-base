<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Twig;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelp;
use OxidEsales\PaymentBase\Twig\ContractStateHelpExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

/**
 * Sprint 14 (MOL-10) — one Twig function hands the shared rows to every module's admin template.
 */
final class ContractStateHelpExtensionTest extends TestCase
{
    public function testRegistersTheHelpFunction(): void
    {
        $functions = (new ContractStateHelpExtension(new ContractStateHelp()))->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('oe_payment_contract_state_help', $functions[0]->getName());
    }

    public function testTheFunctionAnswersTheRows(): void
    {
        $help = new ContractStateHelp();

        self::assertEquals($help->rows(), (new ContractStateHelpExtension($help))->rows());
    }
}
