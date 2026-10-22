<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\OxidGuestBasketClaim;
use OxidEsales\PaymentBase\Checkout\Headless\GuestBasketClaimInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUser;
use PHPUnit\Framework\TestCase;

final class OxidGuestBasketClaimTest extends TestCase
{
    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(GuestBasketClaimInterface::class, new TestableGuestBasketClaim());
    }

    public function testClaim_BasketWithoutOwner_BecomesTheGuests(): void
    {
        $claim = new TestableGuestBasketClaim();
        $claim->owners['ub-1'] = '';

        $claim->claim('ub-1', new GuestUser('guest-1', 'address-1'));

        self::assertSame([['ub-1', 'guest-1', 'address-1']], $claim->assigned);
    }

    public function testClaim_BasketAlreadyTheGuests_IsClaimedAgainWithThisCheckoutsDeliveryAddress(): void
    {
        $claim = new TestableGuestBasketClaim();
        $claim->owners['ub-1'] = 'guest-1';

        $claim->claim('ub-1', new GuestUser('guest-1'));

        self::assertSame([['ub-1', 'guest-1', null]], $claim->assigned, 'the delivery address follows the latest checkout');
    }

    public function testClaim_BasketOfAnotherUser_IsRefusedAsBasketForbidden(): void
    {
        $claim = new TestableGuestBasketClaim();
        $claim->owners['ub-1'] = 'user-7';

        try {
            $claim->claim('ub-1', new GuestUser('guest-1'));
            self::fail('somebody else\'s basket');
        } catch (ShopOrderException $e) {
            self::assertSame('basket_forbidden', $e->getErrorCode());
        }

        self::assertSame([], $claim->assigned);
    }

    public function testClaim_UnknownBasket_IsRefusedAsBasketNotFound(): void
    {
        $claim = new TestableGuestBasketClaim();

        try {
            $claim->claim('ub-9', new GuestUser('guest-1'));
            self::fail('no such basket');
        } catch (ShopOrderException $e) {
            self::assertSame('basket_not_found', $e->getErrorCode());
        }
    }
}

final class TestableGuestBasketClaim extends OxidGuestBasketClaim
{
    /** @var array<string, string> */
    public array $owners = [];

    /** @var list<array{string, string, string|null}> */
    public array $assigned = [];

    protected function ownerOf(string $basketId): ?string
    {
        return $this->owners[$basketId] ?? null;
    }

    protected function assign(string $basketId, string $userId, ?string $deliveryAddressId): void
    {
        $this->assigned[] = [$basketId, $userId, $deliveryAddressId];
    }
}
