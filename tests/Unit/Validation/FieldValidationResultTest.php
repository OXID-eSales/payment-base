<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation;

use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldValidationResult::class)]
class FieldValidationResultTest extends TestCase
{
    public function testValidFactoryCreatesValidResult(): void
    {
        $result = FieldValidationResult::valid();

        $this->assertTrue($result->valid);
        $this->assertNull($result->code);
        $this->assertNull($result->offendingChar);
    }

    public function testDisallowedFactoryCreatesInvalidResult(): void
    {
        $result = FieldValidationResult::disallowed('!');

        $this->assertFalse($result->valid);
        $this->assertSame(FieldValidationResult::CODE_DISALLOWED_CHARACTER, $result->code);
        $this->assertSame('!', $result->offendingChar);
    }

    public function testBlockedFactoryCreatesInvalidResult(): void
    {
        $result = FieldValidationResult::blocked(':');

        $this->assertFalse($result->valid);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $result->code);
        $this->assertSame(':', $result->offendingChar);
    }

    public function testControlCharacterFactoryCreatesInvalidResult(): void
    {
        $result = FieldValidationResult::controlCharacter("\t");

        $this->assertFalse($result->valid);
        $this->assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $result->code);
        $this->assertSame("\t", $result->offendingChar);
    }

    public function testCodeConstantsAreStrings(): void
    {
        $this->assertSame('disallowed_character', FieldValidationResult::CODE_DISALLOWED_CHARACTER);
        $this->assertSame('blocked_character', FieldValidationResult::CODE_BLOCKED_CHARACTER);
        $this->assertSame('control_character', FieldValidationResult::CODE_CONTROL_CHARACTER);
    }
}
