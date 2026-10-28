<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Help;

use OxidEsales\PaymentBase\Admin\Help\LangFileHelpTranslator;
use PHPUnit\Framework\TestCase;

final class LangFileHelpTranslatorTest extends TestCase
{
    private const IDENT = 'PAYMENT_ADMIN_HELP_STATE_PENDING';

    public function testTranslate_English_ReadsTheModulesAdminLangFile(): void
    {
        $text = $this->translator()->translate(self::IDENT, 'en');

        self::assertStringContainsString('payment network has not confirmed yet', $text);
    }

    public function testTranslate_German_ReadsTheGermanFile(): void
    {
        self::assertStringContainsString('Zahlungsnetz', $this->translator()->translate(self::IDENT, 'de'));
    }

    public function testTranslate_UnknownLanguage_FallsBackToEnglish(): void
    {
        self::assertSame($this->translator()->translate(self::IDENT, 'en'), $this->translator()->translate(self::IDENT, 'xx'));
        self::assertSame($this->translator()->translate(self::IDENT, 'en'), $this->translator()->translate(self::IDENT, '../../etc'));
    }

    public function testTranslate_UnknownIdent_ReturnsTheIdent(): void
    {
        self::assertSame('NOPE', $this->translator()->translate('NOPE', 'en'));
    }

    private function translator(): LangFileHelpTranslator
    {
        return new LangFileHelpTranslator(__DIR__ . '/../../../../views/admin_twig');
    }
}
