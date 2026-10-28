<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Panel;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminActionRefusedExceptionTest extends TestCase
{
    public function testItCarriesTheProvidersCodeAndSentence(): void
    {
        $refusal = new AdminActionRefusedException('amount_below_minimum', 'The amount is below the provider minimum.');

        self::assertSame('amount_below_minimum', $refusal->refusalCode());
        self::assertSame('The amount is below the provider minimum.', $refusal->getMessage());
        self::assertFalse($refusal->feedbackRecorded());
        self::assertInstanceOf(RuntimeException::class, $refusal);
    }

    public function testTheSentenceIsCutAt255Characters_AndLosesControlCharacters(): void
    {
        $refusal = new AdminActionRefusedException('x', "line one\nline two" . str_repeat('é', 300));

        self::assertSame(255, mb_strlen($refusal->getMessage()));
        self::assertStringNotContainsString("\n", $refusal->getMessage());
        self::assertStringStartsWith('line one line two', $refusal->getMessage());
    }

    public function testACodeThatIsNotASlug_BecomesTheGenericOne(): void
    {
        self::assertSame('refused', (new AdminActionRefusedException('Not A Slug!', 'm'))->refusalCode());
        self::assertSame('refused', (new AdminActionRefusedException('', 'm'))->refusalCode());
    }

    public function testAProviderThatAlreadyShowedItsClassicFeedback_SaysSo(): void
    {
        self::assertTrue((new AdminActionRefusedException('x', 'm', feedbackRecorded: true))->feedbackRecorded());
    }
}
