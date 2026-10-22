<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\DataType;

use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\GraphQL\DataType\GuestCheckoutInputFactory;
use OxidEsales\PaymentBase\GraphQL\Exception\GuestDetailsRefused;
use PHPUnit\Framework\TestCase;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * The `guest` input of every `<provider>CheckoutStart` builds payment-base's value
 * objects; their refusals leave as client-safe GuestDetailsRefused errors with the
 * stable code (the schema-level proof is in the integration suite).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(GuestCheckoutInputFactory::class)]
final class GuestCheckoutInputFactoryTest extends TestCase
{
    public function testGuestAddress_AllFields_MapsToTheValueObject(): void
    {
        $address = GuestCheckoutInputFactory::guestAddress(
            'Gerda',
            'Guest',
            'Grunewaldstr.',
            '1',
            '79098',
            'Freiburg',
            new ID('country-de'),
            'MRS',
            'Acme',
            'c/o Hinterhaus',
            new ID('state-bw'),
            '+49 761 1234'
        );

        $this->assertSame('country-de', $address->countryId);
        $this->assertSame('state-bw', $address->stateId);
        $this->assertSame('MRS', $address->salutation);
        $this->assertSame('Acme', $address->company);
        $this->assertSame('c/o Hinterhaus', $address->additionalInfo);
        $this->assertSame('+49 761 1234', $address->phone);
    }

    public function testGuestAddress_OptionalFieldsOmitted_AreEmptyStrings(): void
    {
        $address = $this->address();

        $this->assertSame('', $address->salutation);
        $this->assertSame('', $address->stateId);
    }

    public function testGuestAddress_MissingRequiredField_IsAGuestDetailsRefusedNamingTheField(): void
    {
        try {
            GuestCheckoutInputFactory::guestAddress('Gerda', 'Guest', ' ', '1', '79098', 'Freiburg', new ID('country-de'));
            $this->fail('a blank street is refused');
        } catch (GuestDetailsRefused $error) {
            $this->assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $error->getExtensions()['errorCode']);
            $this->assertStringContainsString('street', $error->getMessage());
        }
    }

    public function testGuestDetails_ValidEmailWithoutDelivery_KeepsDeliveryNull(): void
    {
        $details = GuestCheckoutInputFactory::guestDetails('guest@example.com', $this->address());

        $this->assertSame('guest@example.com', $details->email);
        $this->assertNull($details->deliveryAddress);
    }

    public function testGuestDetails_WithDelivery_KeepsBothAddresses(): void
    {
        $delivery = $this->address();

        $details = GuestCheckoutInputFactory::guestDetails('guest@example.com', $this->address(), $delivery);

        $this->assertSame($delivery, $details->deliveryAddress);
    }

    public function testGuestDetails_InvalidEmail_IsAGuestDetailsRefusedThatDoesNotEchoTheValue(): void
    {
        try {
            GuestCheckoutInputFactory::guestDetails('nobody-at-example', $this->address());
            $this->fail('an invalid e-mail is refused');
        } catch (GuestDetailsRefused $error) {
            $this->assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $error->getExtensions()['errorCode']);
            $this->assertStringContainsString('email', $error->getMessage());
            $this->assertStringNotContainsString('nobody', $error->getMessage());
        }
    }

    private function address(): GuestAddress
    {
        return GuestCheckoutInputFactory::guestAddress('Gerda', 'Guest', 'Grunewaldstr.', '1', '79098', 'Freiburg', new ID('country-de'));
    }
}
