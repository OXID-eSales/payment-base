<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Help;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 14 (MOL-10) — guards the shared Help markup every provider includes. Source assertions, as
 * PaymentAdminTabTemplateGuardTest: the Unit suite renders no admin templates.
 */
final class ContractStateHelpTemplatesGuardTest extends TestCase
{
    public function testTableRendersTwoColumnsAndAnOptionalProviderColumn(): void
    {
        $t = $this->source('views/twig/admin/help/contract_state_table.html.twig');

        self::assertStringContainsString('oe_payment_contract_state_help()', $t, 'rows come from the Twig function');
        foreach (['PAYMENT_ADMIN_HELP_COL_CONTRACT_STATE', 'PAYMENT_ADMIN_HELP_COL_MEANING', 'PAYMENT_ADMIN_HELP_NONE'] as $ident) {
            self::assertStringContainsString("'$ident'", $t);
        }
        self::assertStringContainsString('row.meaningIdent', $t);
        self::assertStringContainsString('providerLabel', $t, 'the third column is optional and provider-named');
        self::assertStringContainsString('providerStatuses', $t);
        self::assertStringContainsString('row.key()', $t, 'provider statuses are looked up by the row key');
    }

    public function testHintRendersAnAccessibleIconAndAPopupWithTheTable(): void
    {
        $t = $this->source('views/twig/admin/help/contract_state_hint.html.twig');

        self::assertStringContainsString('@oe_payment_base/admin/help/contract_state_table.html.twig', $t, 'the popup shows the shared table');
        self::assertStringContainsString('type="button"', $t);
        self::assertStringContainsString('aria-expanded="false"', $t);
        self::assertStringContainsString('aria-haspopup="dialog"', $t);
        self::assertStringContainsString('role="dialog"', $t);
        self::assertStringContainsString('pc-help-hint', $t);
        self::assertStringContainsString('Escape', $t, 'the layer closes on Escape');
        self::assertStringContainsString('intro', $t, 'the provider passes its description');
    }

    public function testModuleConfigOverrideAddsTheHelpGroupAfterTheLastGroupForPaymentBaseOnly(): void
    {
        $t = $this->source('views/twig/extensions/themes/admin_twig/module_config.html.twig');

        self::assertSame(1, preg_match('/{% block admin_module_config_group %}(.*?){% endblock %}/s', $t, $m));
        self::assertStringContainsString('{{ parent() }}', $m[1], 'every stock group still renders; other modules override this template too');
        self::assertStringContainsString('loop.last', $m[1]);
        self::assertStringContainsString("getEditObjectId() == 'oe_payment_base'", $m[1]);
        self::assertStringContainsString("'PAYMENT_ADMIN_HELP'", $m[1]);
        self::assertStringContainsString('@oe_payment_base/admin/help/contract_state_table.html.twig', $m[1]);
        self::assertStringNotContainsString('providerStatuses:', $m[1], 'payment-base shows the two generic columns only');
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
