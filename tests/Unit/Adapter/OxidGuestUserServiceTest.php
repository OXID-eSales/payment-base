<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\OxidGuestUserService;
use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use PHPUnit\Framework\TestCase;

/**
 * The shop seams (user lookup, country check, saves) are replaced; the rules
 * under test are: new e-mail -> guest account, a guest e-mail -> the same
 * account with fresh addresses, a registered e-mail -> `account_exists`.
 */
final class OxidGuestUserServiceTest extends TestCase
{
    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(GuestUserServiceInterface::class, new TestableGuestUserService());
    }

    public function testUserFor_UnknownEmail_CreatesAGuestAccountFromTheInvoiceAddress(): void
    {
        $service = new TestableGuestUserService();

        $guest = $service->userFor($this->details('Guest@Example.COM'));

        self::assertSame('new-user-1', $guest->userId);
        self::assertNull($guest->deliveryAddressId);
        self::assertCount(1, $service->savedUsers);
        $saved = $service->savedUsers[0];
        self::assertNull($saved['id']);
        self::assertSame('guest@example.com', $saved['fields']['oxusername'], 'e-mails are compared lower-case');
        self::assertSame('', $saved['fields']['oxpassword'], 'a guest has no password');
        self::assertSame('user', $saved['fields']['oxrights']);
        self::assertSame('Ada', $saved['fields']['oxfname']);
        self::assertSame('Analytical Way', $saved['fields']['oxstreet']);
        self::assertSame('1', $saved['fields']['oxstreetnr']);
        self::assertSame('10115', $saved['fields']['oxzip']);
        self::assertSame('Berlin', $saved['fields']['oxcity']);
        self::assertSame(TestableGuestUserService::KNOWN_COUNTRY, $saved['fields']['oxcountryid']);
    }

    public function testUserFor_ExistingEmailWithoutPassword_ReusesGuestUser(): void
    {
        $service = new TestableGuestUserService();
        $service->accounts['guest@example.com'] = ['id' => 'guest-9', 'passwordHash' => ''];

        $guest = $service->userFor($this->details('guest@example.com'));

        self::assertSame('guest-9', $guest->userId);
        self::assertSame('guest-9', $service->savedUsers[0]['id'], 'the same account is brought up to date');
    }

    public function testUserFor_ExistingRegisteredEmail_RefusesWithAccountExistsAndTouchesNothing(): void
    {
        $service = new TestableGuestUserService();
        $service->accounts['guest@example.com'] = ['id' => 'user-7', 'passwordHash' => '$2y$10$abcdefghijklmnopqrstuv'];

        try {
            $service->userFor($this->details('guest@example.com'));
            self::fail('a registered e-mail must log in');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::ACCOUNT_EXISTS, $e->errorCode);
            self::assertStringNotContainsString('guest@example.com', $e->getMessage());
        }

        self::assertSame([], $service->savedUsers, 'a registered account is never changed by a stranger');
        self::assertSame([], $service->savedAddresses);
    }

    public function testUserFor_CountryUnknownToTheShop_RefusesAsGuestDetailsRequired(): void
    {
        $service = new TestableGuestUserService();

        try {
            $service->userFor($this->details('guest@example.com', countryId: 'nowhere'));
            self::fail('unknown country');
        } catch (HeadlessCheckoutException $e) {
            self::assertSame(HeadlessCheckoutException::GUEST_DETAILS_REQUIRED, $e->errorCode);
            self::assertStringContainsString('countryId', $e->getMessage());
        }

        self::assertSame([], $service->savedUsers);
    }

    public function testUserFor_DeliveryAddressGiven_IsSavedForTheGuestAndReturned(): void
    {
        $service = new TestableGuestUserService();
        $delivery = new GuestAddress(
            firstName: 'Charles',
            lastName: 'Babbage',
            street: 'Difference Road',
            streetNumber: '2',
            zipCode: '80331',
            city: 'Munich',
            countryId: TestableGuestUserService::KNOWN_COUNTRY,
            company: 'Engines Ltd',
        );

        $guest = $service->userFor($this->details('guest@example.com', delivery: $delivery));

        self::assertSame('address-1', $guest->deliveryAddressId);
        self::assertSame('new-user-1', $service->savedAddresses[0]['userId']);
        self::assertSame('Babbage', $service->savedAddresses[0]['fields']['oxlname']);
        self::assertSame('Engines Ltd', $service->savedAddresses[0]['fields']['oxcompany']);
    }

    public function testUserFor_NoDeliveryAddress_ReplacesThePreviousOneWithNothing(): void
    {
        $service = new TestableGuestUserService();
        $service->accounts['guest@example.com'] = ['id' => 'guest-9', 'passwordHash' => ''];

        $guest = $service->userFor($this->details('guest@example.com'));

        self::assertNull($guest->deliveryAddressId);
        self::assertSame([['userId' => 'guest-9', 'fields' => null]], $service->addressReplacements);
    }

    private function details(string $email, string $countryId = TestableGuestUserService::KNOWN_COUNTRY, ?GuestAddress $delivery = null): GuestDetails
    {
        return new GuestDetails($email, new GuestAddress(
            firstName: 'Ada',
            lastName: 'Lovelace',
            street: 'Analytical Way',
            streetNumber: '1',
            zipCode: '10115',
            city: 'Berlin',
            countryId: $countryId,
        ), $delivery);
    }
}

final class TestableGuestUserService extends OxidGuestUserService
{
    public const KNOWN_COUNTRY = 'a7c40f631fc920687.20179984';

    /** @var array<string, array{id: string, passwordHash: string}> */
    public array $accounts = [];

    /** @var list<array{id: string|null, fields: array<string, string>}> */
    public array $savedUsers = [];

    /** @var list<array{userId: string, fields: array<string, string>}> */
    public array $savedAddresses = [];

    /** @var list<array{userId: string, fields: array<string, string>|null}> */
    public array $addressReplacements = [];

    protected function findAccount(string $email): ?array
    {
        return $this->accounts[$email] ?? null;
    }

    protected function countryExists(string $countryId): bool
    {
        return $countryId === self::KNOWN_COUNTRY;
    }

    protected function saveUser(?string $id, array $fields): string
    {
        $this->savedUsers[] = ['id' => $id, 'fields' => $fields];

        return $id ?? 'new-user-1';
    }

    protected function replaceDeliveryAddress(string $userId, ?array $fields): ?string
    {
        $this->addressReplacements[] = ['userId' => $userId, 'fields' => $fields];
        if ($fields === null) {
            return null;
        }
        $this->savedAddresses[] = ['userId' => $userId, 'fields' => $fields];

        return 'address-' . count($this->savedAddresses);
    }
}
