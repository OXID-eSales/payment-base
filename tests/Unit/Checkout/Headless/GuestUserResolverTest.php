<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolver;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use PHPUnit\Framework\TestCase;

final class TestableGuestUserResolver extends GuestUserResolver
{
    /** @var array<string, string> email => user id */
    public array $existing = [];

    /** @var array<string, string> iso2 => country id */
    public array $countries = ['DE' => 'country-de'];

    /** @var list<array<string, string>> */
    public array $created = [];

    protected function findUserIdByEmail(string $email): ?string
    {
        return $this->existing[strtolower($email)] ?? null;
    }

    protected function countryIdFor(string $iso2): ?string
    {
        return $this->countries[strtoupper($iso2)] ?? null;
    }

    protected function createUser(array $fields): string
    {
        $this->created[] = $fields;

        return 'user-new';
    }
}

/**
 * Sprint 15 / S7 — a buyer an agent describes (ACP `buyer` +
 * `fulfillment_address`) becomes the shop user the contract and the order
 * belong to: the existing account for that e-mail, or a guest account with
 * the given address - the headless analogue of PayPal express filling in the
 * buyer in BeforePlaceOrder.
 */
final class GuestUserResolverTest extends TestCase
{
    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(GuestUserResolverInterface::class, new TestableGuestUserResolver());
    }

    public function testAnExistingAccountForTheEmailIsReused(): void
    {
        $resolver = new TestableGuestUserResolver();
        $resolver->existing['shopper@example.com'] = 'user-1';

        self::assertSame('user-1', $resolver->resolve(['email' => 'Shopper@Example.com'], null));
        self::assertSame([], $resolver->created);
    }

    public function testAGuestAccountIsCreatedFromBuyerAndAddress(): void
    {
        $resolver = new TestableGuestUserResolver();

        $userId = $resolver->resolve(
            ['email' => 'new@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone_number' => '+49 30 1'],
            ['name' => 'Ada Lovelace', 'line_one' => 'Analytical Way 1', 'line_two' => 'c/o Babbage', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'de', 'state' => '']
        );

        self::assertSame('user-new', $userId);
        self::assertSame([[
            'oxusername' => 'new@example.com',
            'oxfname' => 'Ada',
            'oxlname' => 'Lovelace',
            'oxfon' => '+49 30 1',
            'oxstreet' => 'Analytical Way 1',
            'oxstreetnr' => '',
            'oxaddinfo' => 'c/o Babbage',
            'oxcity' => 'Berlin',
            'oxzip' => '10115',
            'oxcountryid' => 'country-de',
            'oxstateid' => '',
        ]], $resolver->created);
    }

    public function testTheNameOnTheAddressFillsInMissingBuyerNames(): void
    {
        $resolver = new TestableGuestUserResolver();

        $resolver->resolve(['email' => 'n@example.com'], ['name' => 'Grace Brewster Hopper', 'line_one' => 'Navy Rd 2', 'city' => 'Arlington', 'postal_code' => '22201', 'country' => 'DE']);

        self::assertSame('Grace', $resolver->created[0]['oxfname']);
        self::assertSame('Brewster Hopper', $resolver->created[0]['oxlname']);
    }

    public function testWithoutAnEmailNothingCanBeResolved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TestableGuestUserResolver())->resolve(['first_name' => 'x'], null);
    }

    public function testAnUnknownCountryIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TestableGuestUserResolver())->resolve(['email' => 'n@example.com'], ['line_one' => 'x', 'city' => 'y', 'postal_code' => '1', 'country' => 'ZZ']);
    }

    public function testANewBuyerWithoutAnAddressStillGetsAnAccount(): void
    {
        $resolver = new TestableGuestUserResolver();

        $userId = $resolver->resolve(['email' => 'n@example.com', 'first_name' => 'N', 'last_name' => 'O'], null);

        self::assertSame('user-new', $userId);
        self::assertSame('', $resolver->created[0]['oxcountryid']);
    }
}
