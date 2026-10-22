<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestDetailsTest extends TestCase
{
    public function testConstruct_CompleteDetails_ExposesThemWithoutNulls(): void
    {
        $invoice = self::address();

        $details = new GuestDetails('guest@example.com', $invoice);

        self::assertSame('guest@example.com', $details->email);
        self::assertSame($invoice, $details->invoiceAddress);
        self::assertNull($details->deliveryAddress);
        self::assertSame('', $invoice->company);
        self::assertSame('', $invoice->phone);
    }

    public function testConstruct_WithADeliveryAddress_KeepsIt(): void
    {
        $delivery = self::address(street: 'Elsewhere');

        $details = new GuestDetails('guest@example.com', self::address(), $delivery);

        self::assertSame($delivery, $details->deliveryAddress);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEmails(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'no at sign' => ['guest.example.com'],
            'no domain' => ['guest@'],
            'with a space' => ['gu est@example.com'],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function testConstruct_InvalidEmail_IsRefusedAsGuestDetailsRequired(string $email): void
    {
        try {
            new GuestDetails($email, self::address());
            self::fail('an invalid e-mail must be refused');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $e->errorCode);
            self::assertStringContainsString('email', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredAddressFields(): array
    {
        return [
            'firstName' => ['firstName'],
            'lastName' => ['lastName'],
            'street' => ['street'],
            'streetNumber' => ['streetNumber'],
            'zipCode' => ['zipCode'],
            'city' => ['city'],
            'countryId' => ['countryId'],
        ];
    }

    #[DataProvider('requiredAddressFields')]
    public function testAddress_BlankRequiredField_IsRefusedNamingTheField(string $field): void
    {
        try {
            self::address(...[$field => '  ']);
            self::fail("$field is required");
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $e->errorCode);
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    public function testAddress_ZipCodeLongerThanTheColumn_IsRefusedInsteadOfTruncated(): void
    {
        $this->expectException(HeadlessCheckoutException::class);
        $this->expectExceptionMessage('zipCode');

        self::address(zipCode: str_repeat('1', 17));
    }

    public function testAddress_ErrorMessage_NeverEchoesTheValue(): void
    {
        try {
            new GuestDetails('secret-looking-value', self::address());
            self::fail('invalid e-mail');
        } catch (HeadlessCheckoutException $e) {
            self::assertStringNotContainsString('secret-looking-value', $e->getMessage());
        }
    }

    private static function address(
        string $firstName = 'Ada',
        string $lastName = 'Lovelace',
        string $street = 'Analytical Way',
        string $streetNumber = '1',
        string $zipCode = '10115',
        string $city = 'Berlin',
        string $countryId = 'a7c40f631fc920687.20179984',
    ): GuestAddress {
        return new GuestAddress(
            firstName: $firstName,
            lastName: $lastName,
            street: $street,
            streetNumber: $streetNumber,
            zipCode: $zipCode,
            city: $city,
            countryId: $countryId,
        );
    }
}
