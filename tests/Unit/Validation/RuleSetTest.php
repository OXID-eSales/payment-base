<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation;

use OxidEsales\PaymentBase\Validation\RuleSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleSet::class)]
class RuleSetTest extends TestCase
{
    // RED test 6
    public function testParsesAllowAndBlockTokens(): void
    {
        $ruleSet = RuleSet::fromArray([
            'allow' => "UNICODE_LETTERS SPACES ' - .",
        ]);

        $allowTokens = $ruleSet->getAllowTokens();
        $this->assertSame(['UNICODE_LETTERS', 'SPACES', "'", '-', '.'], $allowTokens);
    }

    // RED test 7
    public function testDoubleSpaceIsTreatedAsTokenSeparatorOnly(): void
    {
        $ruleSet = RuleSet::fromArray([
            'allow' => "LETTERS  NUMBERS",
        ]);

        $allowTokens = $ruleSet->getAllowTokens();
        $this->assertSame(['LETTERS', 'NUMBERS'], $allowTokens);
    }

    public function testParsesBlockTokens(): void
    {
        $ruleSet = RuleSet::fromArray([
            'allow' => 'LETTERS',
            'block' => ': ;',
        ]);

        $this->assertSame([':', ';'], $ruleSet->getBlockTokens());
    }

    public function testEmptyAllowGivesEmptyTokens(): void
    {
        $ruleSet = RuleSet::fromArray([]);

        $this->assertSame([], $ruleSet->getAllowTokens());
        $this->assertSame([], $ruleSet->getBlockTokens());
    }

    public function testHasAllowConstraintReturnsFalseWhenNoAllow(): void
    {
        $ruleSet = RuleSet::fromArray([]);

        $this->assertFalse($ruleSet->hasAllowConstraint());
    }

    public function testHasAllowConstraintReturnsTrueWhenAllowPresent(): void
    {
        $ruleSet = RuleSet::fromArray(['allow' => 'LETTERS']);

        $this->assertTrue($ruleSet->hasAllowConstraint());
    }
}
