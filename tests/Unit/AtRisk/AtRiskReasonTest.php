<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\AtRisk;

use DateTimeImmutable;
use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRisk;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentAtRisk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every reason a payment can be flagged with has a stored value that fits the column, a text
 * in the classic admin (en + de) and a GraphQL value.
 */
final class AtRiskReasonTest extends TestCase
{
    private const REASON_COLUMN_WIDTH = 32;

    /**
     * VR-26 D5: a chargeback is its own reason, not a "dispute opened".
     */
    #[Test]
    public function chargebackIsAReason(): void
    {
        self::assertSame('chargeback', AtRiskReason::Chargeback->value);
        self::assertSame(AtRiskReason::Chargeback, AtRiskReason::from('chargeback'));
    }

    #[Test]
    public function chargebackIsExposedToGraphQlInUpperCase(): void
    {
        $flag = new PaymentAtRisk('f-1', 'c-1', 'order-1', AtRiskReason::Chargeback, 'chb_1', new DateTimeImmutable());

        self::assertSame('CHARGEBACK', (new AdminPaymentAtRisk($flag))->reason());
    }

    /**
     * @return array<string, array{AtRiskReason}>
     */
    public static function reasons(): array
    {
        $cases = [];
        foreach (AtRiskReason::cases() as $reason) {
            $cases[$reason->value] = [$reason];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('reasons')]
    public function everyReasonFitsTheColumn(AtRiskReason $reason): void
    {
        self::assertLessThanOrEqual(self::REASON_COLUMN_WIDTH, strlen($reason->value));
    }

    #[Test]
    #[DataProvider('reasons')]
    public function everyReasonHasAnAdminTextInBothLanguages(AtRiskReason $reason): void
    {
        $key = 'PAYMENT_ADMIN_AT_RISK_' . strtoupper($reason->value);
        foreach (['en', 'de'] as $language) {
            $lang = $this->languageFile($language);

            self::assertArrayHasKey($key, $lang, $language);
            self::assertNotSame('', $lang[$key], $language);
        }
    }

    /**
     * @return array<string, string>
     */
    private function languageFile(string $language): array
    {
        $aLang = [];
        require dirname(__DIR__, 3) . '/views/admin_twig/' . $language . '/payment_admin_lang.php';

        return $aLang;
    }
}
