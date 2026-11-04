<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\User;

use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\PaymentBase\Validation\RuleSet;
use OxidEsales\PaymentBase\Validation\User\FieldValidationFailure;
use OxidEsales\PaymentBase\Validation\User\UserDataValidator;
use OxidEsales\PaymentBase\Validation\User\UserDataValidatorInterface;
use OxidEsales\PaymentBase\Validation\User\UserFieldReaderInterface;
use OxidEsales\PaymentBase\Validation\ValidationBaseFactory;
use OxidEsales\PaymentBase\Validation\ValidationBaseFactoryInterface;
use OxidEsales\PaymentBase\Validation\ValidationBaseInterface;
use OxidEsales\PaymentBase\Validation\ValidationRuleLoaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Union of the Stripe and Mollie UserDataValidator tests, run against the shared validator.
 * The real engine is driven with rules mirroring the providers' rules files.
 */
#[CoversClass(UserDataValidator::class)]
#[CoversClass(FieldValidationFailure::class)]
final class UserDataValidatorTest extends TestCase
{
    private const MODULE_ID = 'oe_payments_test';

    public function testFieldValidationFailureCarriesItsValues(): void
    {
        $failure = new FieldValidationFailure('firstName', 'billing', 'blocked_character', ':', 'oxfname');

        self::assertSame('firstName', $failure->field);
        self::assertSame('billing', $failure->addressKind);
        self::assertSame('blocked_character', $failure->code);
        self::assertSame(':', $failure->offendingChar);
        self::assertSame('oxfname', $failure->oxidColumn);
    }

    public function testFieldValidationFailureAllowsNullCharAndColumn(): void
    {
        $failure = new FieldValidationFailure('city', 'delivery', 'disallowed_character', null, null);

        self::assertNull($failure->offendingChar);
        self::assertNull($failure->oxidColumn);
    }

    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(UserDataValidatorInterface::class, $this->validator());
    }

    public function testAcceptsCleanBillingFieldsOfEveryLogicalName(): void
    {
        $reader = $this->readerWith([
            'firstName' => "O'Connor", 'lastName' => 'Anne-Marie', 'street' => 'Main St.', 'houseNumber' => '12a',
            'postalCode' => '10115', 'city' => 'Köln', 'vatId' => 'DE123456789', 'phone' => '+49 30 123-4567',
        ]);

        self::assertSame([], $this->validator()->validateForUser(self::MODULE_ID, $reader));
    }

    public function testReportsBlockedCharacterWithFieldCodeCharAndOxidColumn(): void
    {
        $failures = $this->validator()->validateForUser(self::MODULE_ID, $this->readerWith(['firstName' => 'Jane<script>']));

        self::assertCount(1, $failures);
        self::assertSame('firstName', $failures[0]->field);
        self::assertSame('blocked_character', $failures[0]->code);
        self::assertSame('<', $failures[0]->offendingChar);
        self::assertSame('oxfname', $failures[0]->oxidColumn);
        self::assertSame('billing', $failures[0]->addressKind);
    }

    public function testReportsControlCharacterInStreet(): void
    {
        $failures = $this->validator()->validateForUser(self::MODULE_ID, $this->readerWith(['street' => "Main\tStreet"]));

        self::assertCount(1, $failures);
        self::assertSame('street', $failures[0]->field);
        self::assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $failures[0]->code);
    }

    public function testUnicodeLettersPass(): void
    {
        $reader = $this->readerWith(['firstName' => 'Björn', 'lastName' => 'Müller-Łąka', 'city' => 'Świętokrzyskie']);

        self::assertSame([], $this->validator()->validateForUser(self::MODULE_ID, $reader));
    }

    public function testEmailFieldIsCheckedWhenTheModuleDeclaresARuleForIt(): void
    {
        self::assertSame([], $this->validator()->validateForUser(self::MODULE_ID, $this->readerWith(['email' => 'jane.doe+test@example.com'])));

        $failures = $this->validator()->validateForUser(self::MODULE_ID, $this->readerWith(['email' => 'jane<doe@example.com']));

        self::assertCount(1, $failures);
        self::assertSame('email', $failures[0]->field);
        self::assertSame('oxusername', $failures[0]->oxidColumn);
    }

    public function testEmailFieldIsAcceptedWhenTheModuleHasNoRuleForIt(): void
    {
        $failures = $this->validator(withEmailRule: false)
            ->validateForUser(self::MODULE_ID, $this->readerWith(['email' => 'jane<doe@example.com']));

        self::assertSame([], $failures);
    }

    public function testSkipsEmptyValuesWithoutAskingTheEngine(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase->expects(self::never())->method('validateField');

        $this->validatorOver($validationBase)->validateForUser(self::MODULE_ID, $this->readerWith(['company' => '']));
    }

    public function testValidatesTheDeliveryAddressWhenOneIsSelected(): void
    {
        $reader = $this->readerWith(
            ['firstName' => 'Jane', 'street' => 'Main Street'],
            ['firstName' => 'Jane', 'street' => 'Elm Street <script>'],
        );

        $failures = $this->validator()->validateForUser(self::MODULE_ID, $reader);

        self::assertCount(1, $failures);
        self::assertSame('street', $failures[0]->field);
        self::assertSame('delivery', $failures[0]->addressKind);
        self::assertSame('oxstreet', $failures[0]->oxidColumn);
    }

    public function testSkipsTheDeliveryPassWhenNoDeliveryAddressIsSelected(): void
    {
        self::assertSame([], $this->validator()->validateForUser(self::MODULE_ID, $this->readerWith(['firstName' => 'Jane'])));
    }

    public function testKnowsPostalCodeAndThePhoneTrio(): void
    {
        $reader = $this->readerWith([
            'postalCode' => '10117<', 'cellPhone' => '+49 170 <1>', 'personalPhone' => '030 ; 1', 'fax' => '030 | 2',
        ]);

        $fields = array_map(
            static fn (FieldValidationFailure $failure): string => $failure->field,
            $this->validator()->validateForUser(self::MODULE_ID, $reader)
        );

        self::assertSame(['postalCode', 'cellPhone', 'personalPhone', 'fax'], $fields);
    }

    public function testFieldMapHappyPath(): void
    {
        self::assertSame([], $this->validator()->validateFieldMap(self::MODULE_ID, ['firstName' => 'Alice']));
    }

    public function testFieldMapReportsFailuresWithoutOxidColumn(): void
    {
        $failures = $this->validator()->validateFieldMap(self::MODULE_ID, ['phone' => '+49 (0)30-12345 <injected>']);

        self::assertCount(1, $failures);
        self::assertSame('phone', $failures[0]->field);
        self::assertSame('billing', $failures[0]->addressKind);
        self::assertNull($failures[0]->oxidColumn);
    }

    public function testFieldMapCarriesTheGivenAddressKind(): void
    {
        $failures = $this->validator()->validateFieldMap(
            self::MODULE_ID,
            ['refundDescription' => 'chargeback <x>'],
            FieldValidationFailure::KIND_ADMIN
        );

        self::assertCount(1, $failures);
        self::assertSame('admin', $failures[0]->addressKind);
        self::assertSame('refundDescription', $failures[0]->field);
    }

    public function testAdminFreeTextAcceptsUmlautsAndAllowedLiterals(): void
    {
        $failures = $this->validator()->validateFieldMap(
            self::MODULE_ID,
            ['captureReason' => 'Rückerstattung (Teillieferung) #2: Kunde', 'refundDescription' => 'Erstattung für #42 (Teil 1/2)'],
            'admin'
        );

        self::assertSame([], $failures);
    }

    public function testAdminFreeTextRejectsScriptTagCurlyBraceAndControlCharacter(): void
    {
        $validator = $this->validator();

        $script = $validator->validateFieldMap(self::MODULE_ID, ['captureReason' => '<script>alert(1)</script>'], 'admin');
        $brace = $validator->validateFieldMap(self::MODULE_ID, ['captureReason' => 'reason {x}'], 'admin');
        $control = $validator->validateFieldMap(self::MODULE_ID, ['captureReason' => "reason\u{0000}"], 'admin');

        self::assertSame([FieldValidationResult::CODE_BLOCKED_CHARACTER, '<'], [$script[0]->code, $script[0]->offendingChar]);
        self::assertSame([FieldValidationResult::CODE_BLOCKED_CHARACTER, '{'], [$brace[0]->code, $brace[0]->offendingChar]);
        self::assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $control[0]->code);
        self::assertSame('admin', $script[0]->addressKind);
        self::assertNull($script[0]->oxidColumn);
    }

    public function testEmptyAdminFreeTextIsValid(): void
    {
        self::assertSame([], $this->validator()->validateFieldMap(self::MODULE_ID, ['captureReason' => ''], 'admin'));
    }

    public function testTheRulesOfTheGivenModuleIdApply(): void
    {
        $requestedModuleIds = [];
        $factory = $this->createMock(ValidationBaseFactoryInterface::class);
        $factory->method('create')->willReturnCallback(
            function (string $moduleId) use (&$requestedModuleIds): ValidationBaseInterface {
                $requestedModuleIds[] = $moduleId;
                $base = $this->createMock(ValidationBaseInterface::class);
                $base->method('validateField')->willReturn(FieldValidationResult::valid());

                return $base;
            }
        );
        $validator = new UserDataValidator($factory);

        $validator->validateForUser('oe_payments_stripe_wallet', $this->readerWith(['city' => 'Berlin']));
        $validator->validateFieldMap('oe_payments_mollie', ['city' => 'Berlin']);

        self::assertSame(['oe_payments_stripe_wallet', 'oe_payments_mollie'], $requestedModuleIds);
    }

    private function validatorOver(ValidationBaseInterface $validationBase): UserDataValidator
    {
        $factory = $this->createMock(ValidationBaseFactoryInterface::class);
        $factory->method('create')->willReturn($validationBase);

        return new UserDataValidator($factory);
    }

    private function validator(bool $withEmailRule = true): UserDataValidator
    {
        $loader = new class ($withEmailRule) implements ValidationRuleLoaderInterface {
            public function __construct(private readonly bool $withEmailRule)
            {
            }

            /** @return array<string, RuleSet> */
            public function loadFor(string $pluginModuleId): array
            {
                $text = ['allow' => "UNICODE_LETTERS SPACES ' - .", 'block' => ': ; < > { } [ ] | \\ ~ ! @ # $ % ^ * = + " ? , & _'];
                $address = ['allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . /", 'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "'];
                $phone = ['allow' => 'NUMBERS SPACES + - ( )', 'block' => '< > ; |'];
                $adminText = ['allow' => "UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :", 'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = + "'];
                $rules = [
                    'firstName' => $text, 'lastName' => $text, 'city' => $address, 'street' => $address,
                    'houseNumber' => $address, 'postalCode' => ['allow' => 'NUMBERS SPACES -', 'block' => '<'],
                    'vatId' => ['allow' => 'UNICODE_LETTERS NUMBERS'], 'phone' => $phone, 'cellPhone' => $phone,
                    'personalPhone' => $phone, 'fax' => $phone,
                    'captureReason' => $adminText, 'refundDescription' => $adminText,
                ];
                if ($this->withEmailRule) {
                    $rules['email'] = ['allow' => 'UNICODE_LETTERS NUMBERS @ . - _ +', 'block' => '< > ; |'];
                }

                return array_map(static fn (array $rule): RuleSet => RuleSet::fromArray($rule), $rules);
            }
        };

        return new UserDataValidator(new ValidationBaseFactory($loader));
    }

    /**
     * @param array<string, string> $billing
     * @param array<string, string>|null $delivery a selected delivery address, or null for none
     */
    private function readerWith(array $billing, ?array $delivery = null): UserFieldReaderInterface
    {
        return new class ($billing, $delivery) implements UserFieldReaderInterface {
            /**
             * @param array<string, string> $billing
             * @param array<string, string>|null $delivery
             */
            public function __construct(private readonly array $billing, private readonly ?array $delivery)
            {
            }

            public function readBillingField(string $logicalName): string
            {
                return $this->billing[$logicalName] ?? '';
            }

            public function hasDeliveryAddress(): bool
            {
                return $this->delivery !== null;
            }

            public function readDeliveryField(string $logicalName): string
            {
                return $this->delivery[$logicalName] ?? '';
            }
        };
    }
}
