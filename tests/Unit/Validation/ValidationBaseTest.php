<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation;

use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\PaymentBase\Validation\RuleSet;
use OxidEsales\PaymentBase\Validation\ValidationBase;
use OxidEsales\PaymentBase\Validation\ValidationRuleLoaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationBase::class)]
class ValidationBaseTest extends TestCase
{
    private ValidationRuleLoaderInterface&MockObject $loader;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(ValidationRuleLoaderInterface::class);
    }

    private function makeValidator(array $ruleSets): ValidationBase
    {
        $this->loader->method('loadFor')->willReturn($ruleSets);

        return new ValidationBase('test_plugin', $this->loader);
    }

    // RED test 10
    public function testValidatesAllowOnlyField(): void
    {
        $validator = $this->makeValidator([
            'houseNumber' => RuleSet::fromArray(['allow' => 'NUMBERS LETTERS - /']),
        ]);

        $validResult = $validator->validateField('houseNumber', '12a');
        $this->assertTrue($validResult->valid);

        $invalidResult = $validator->validateField('houseNumber', '12!');
        $this->assertFalse($invalidResult->valid);
        $this->assertSame(FieldValidationResult::CODE_DISALLOWED_CHARACTER, $invalidResult->code);
        $this->assertSame('!', $invalidResult->offendingChar);
    }

    // RED test 11
    public function testValidatesAllowAndBlock(): void
    {
        $validator = $this->makeValidator([
            'firstName' => RuleSet::fromArray([
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < >',
            ]),
        ]);

        $this->assertTrue($validator->validateField('firstName', "O'Connor")->valid);
        $this->assertTrue($validator->validateField('firstName', 'Anne-Marie')->valid);

        $blocked = $validator->validateField('firstName', 'O:Connor');
        $this->assertFalse($blocked->valid);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $blocked->code);
        $this->assertSame(':', $blocked->offendingChar);
    }

    // RED test 12
    public function testUniversalBlocklistBeatsAllow(): void
    {
        $validator = $this->makeValidator([
            'additionalInfo' => RuleSet::fromArray([
                'allow' => "LETTERS NUMBERS SPACES ' - . , / #",
            ]),
        ]);

        $result = $validator->validateField('additionalInfo', "Main\tStreet");

        $this->assertFalse($result->valid);
        $this->assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $result->code);
        $this->assertSame("\t", $result->offendingChar);
    }

    // RED test 13
    public function testUnknownFieldNameReturnsValidByDefault(): void
    {
        $validator = $this->makeValidator([]);

        $result = $validator->validateField('foo', 'anything');

        $this->assertTrue($result->valid);
    }

    // RED test 14
    public function testEmptyValueIsValid(): void
    {
        $validator = $this->makeValidator([
            'firstName' => RuleSet::fromArray(['allow' => 'LETTERS']),
        ]);

        $this->assertTrue($validator->validateField('firstName', '')->valid);
        $this->assertTrue($validator->validateField('firstName', null)->valid);
    }

    public function testBlockPrecedesAllow(): void
    {
        $validator = $this->makeValidator([
            'city' => RuleSet::fromArray([
                'allow' => 'UNICODE_LETTERS SPACES',
                'block' => 'ö',
            ]),
        ]);

        // 'ö' would match UNICODE_LETTERS allow but block wins
        $result = $validator->validateField('city', 'Köln');
        $this->assertFalse($result->valid);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $result->code);
        $this->assertSame('ö', $result->offendingChar);
    }

    public function testValidFieldWithUnicodeNamePassesAllow(): void
    {
        $validator = $this->makeValidator([
            'city' => RuleSet::fromArray(['allow' => 'UNICODE_LETTERS SPACES']),
        ]);

        $result = $validator->validateField('city', 'Köln');
        $this->assertTrue($result->valid);
    }
}
