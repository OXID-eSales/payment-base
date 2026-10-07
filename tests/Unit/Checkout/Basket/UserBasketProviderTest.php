<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Application\Model\UserBasketItem;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Basket\UserBasketProvider;
use OxidEsales\PaymentBase\Service\DeliveryAddressHashServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * An `oxuserbaskets` row as the provider sees it: owner, optional
 * storefront-chosen delivery set, items.
 *
 * @phpstan-ignore-next-line
 */
final class FakeUserBasket extends UserBasket
{
    /**
     * @param list<UserBasketItem> $items
     */
    public function __construct(
        private readonly string $ownerId,
        private readonly array $items,
        private readonly ?string $storefrontDeliverySetId = null,
    ) {
        // The storefront's column, present only where graphql-storefront is
        // installed - so the provider must look before it reads.
        if ($storefrontDeliverySetId !== null) {
            $this->oxuserbaskets__oegql_deliverymethodid = new Field($storefrontDeliverySetId, Field::T_RAW);
        }
    }

    public function getFieldData(string $field): mixed
    {
        return match ($field) {
            'oxuserid' => $this->ownerId,
            'oegql_deliverymethodid' => $this->storefrontDeliverySetId,
            default => null,
        };
    }

    public function getItems($blReload = false, $blActiveCheck = true)
    {
        return $this->items;
    }
}

final class FakeUserBasketItem extends UserBasketItem
{
    /**
     * @param array<string, string>|null $selList
     * @param array<string, string>|null $persParams
     */
    public function __construct(
        private readonly string $articleId,
        private readonly float $amount,
        private readonly ?array $selList = null,
        private readonly ?array $persParams = null,
    ) {
    }

    public function getFieldData(string $field): mixed
    {
        return match ($field) {
            'oxartid' => $this->articleId,
            'oxamount' => $this->amount,
            default => null,
        };
    }

    public function getSelList()
    {
        return $this->selList;
    }

    public function getPersParams()
    {
        return $this->persParams;
    }
}

final class HeadlessUser extends User
{
    public function __construct(private readonly string $id)
    {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEncodedDeliveryAddress()
    {
        return 'addr-hash-' . $this->id;
    }
}

/**
 * Records what the provider did to the shop basket it built.
 */
final class RecordingBasket extends Basket
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function setBasketUser($oUser)
    {
        $this->calls[] = 'user:' . $oUser->getId();
    }

    public function addToBasket(
        $sProductID,
        $dAmount,
        $aSel = null,
        $aPersParam = null,
        $blOverride = false,
        $blBundle = false,
        $sOldBasketItemId = null
    ) {
        $this->calls[] = sprintf(
            'add:%s:%s:%s:%s',
            $sProductID,
            $dAmount,
            json_encode($aSel),
            json_encode($aPersParam)
        );

        return null;
    }

    public function setPayment($sPaymentId = null)
    {
        $this->calls[] = 'payment:' . $sPaymentId;
    }

    public function setShipping($sShippingSetId = null)
    {
        $this->calls[] = 'shipping:' . $sShippingSetId;
    }

    public function calculateBasket($blForceUpdate = false)
    {
        $this->calls[] = 'calculate';
    }
}

final class SpyAddressHash implements DeliveryAddressHashServiceInterface
{
    public ?string $restored = null;

    public function restoreHashForValidation(?string $hash): void
    {
        $this->restored = $hash;
    }

    public function getHash(): ?string
    {
        return $this->restored;
    }

    public function hasHash(): bool
    {
        return $this->restored !== null;
    }

    public function clearHash(): void
    {
        $this->restored = null;
    }
}

/**
 * The provider with its four shop seams replaced: the `oxuserbaskets` row,
 * the user, the shop basket core would build, and the delivery-set lookup.
 */
final class TestableUserBasketProvider extends UserBasketProvider
{
    /** @var array<string, UserBasket> */
    public array $userBaskets = [];

    /** @var array<string, User> */
    public array $users = [];

    public RecordingBasket $basket;

    public ?string $resolvedPreferred = 'unasked';

    public function __construct(SpyAddressHash $addressHash)
    {
        parent::__construct($addressHash);
        $this->basket = new RecordingBasket();
    }

    protected function loadUserBasket(string $basketId): ?UserBasket
    {
        return $this->userBaskets[$basketId] ?? null;
    }

    protected function loadUser(string $userId): ?User
    {
        return $this->users[$userId] ?? null;
    }

    protected function newBasket(): Basket
    {
        return $this->basket;
    }

    protected function resolveDeliverySet(User $user, Basket $basket, ?string $preferred): ?string
    {
        $this->resolvedPreferred = $preferred;

        return $preferred ?? 'oxidstandard';
    }
}

/**
 * Sprint 15 / S1 — the headless basket source. The GraphQL Storefront keeps
 * the shopper's basket as an `oxuserbaskets` row; the MCP layer will do the
 * same. This provider turns that row into the calculated shop Basket that
 * Order::finalizeOrder() needs, the way graphql-storefront's own
 * BasketInfrastructure does it, and leaves the delivery-address hash where
 * core's Order::validateDeliveryAddress() reads it.
 */
final class UserBasketProviderTest extends TestCase
{
    private SpyAddressHash $addressHash;

    private TestableUserBasketProvider $provider;

    protected function setUp(): void
    {
        $this->addressHash = new SpyAddressHash();
        $this->provider = new TestableUserBasketProvider($this->addressHash);
        $this->provider->users['user-1'] = new HeadlessUser('user-1');
    }

    public function testImplementsTheProviderContract(): void
    {
        self::assertInstanceOf(CheckoutBasketProviderInterface::class, $this->provider);
    }

    public function testWithoutABasketIdThereIsNothingToProvide(): void
    {
        self::assertNull($this->provider->basketFor($this->request(basketId: null)));
        self::assertSame([], $this->provider->basket->calls);
    }

    public function testAnUnknownBasketIdAnswersNull(): void
    {
        self::assertNull($this->provider->basketFor($this->request(basketId: 'missing')));
        self::assertSame([], $this->provider->basket->calls);
    }

    /**
     * The JWT user may only check out their own basket. A foreign id is
     * refused before any article is loaded - and refused loudly, not as
     * "not found": the row exists, it is just not theirs.
     */
    public function testABasketOfAnotherUserIsRefusedBeforeAnythingIsBuilt(): void
    {
        $this->provider->userBaskets['ub-1'] = new FakeUserBasket('somebody-else', [
            new FakeUserBasketItem('art-1', 1.0),
        ]);

        try {
            $this->provider->basketFor($this->request(basketId: 'ub-1'));
            self::fail('a basket owned by another user must be refused');
        } catch (ShopOrderException $e) {
            self::assertSame(UserBasketProvider::ERROR_BASKET_FORBIDDEN, $e->getErrorCode());
            self::assertSame('ub-1', $e->getContext()['basket_id']);
            self::assertSame('user-1', $e->getContext()['user_id']);
        }

        self::assertSame([], $this->provider->basket->calls);
        self::assertNull($this->addressHash->restored);
    }

    public function testBuildsTheShopBasketFromTheRowsItemsPaymentAndDeliverySet(): void
    {
        $this->provider->userBaskets['ub-1'] = new FakeUserBasket('user-1', [
            new FakeUserBasketItem('art-1', 2.0, ['color' => 'red'], ['engraving' => 'hi']),
            new FakeUserBasketItem('art-2', 1.0),
        ]);

        $basket = $this->provider->basketFor($this->request(basketId: 'ub-1'));

        self::assertSame($this->provider->basket, $basket, 'the basket core will finalize is the one the provider built');
        self::assertSame([
            'user:user-1',
            'add:art-1:2:{"color":"red"}:{"engraving":"hi"}',
            'add:art-2:1:null:null',
            'payment:oe_payments_stripe_wallet',
            'calculate',
            'shipping:oxidstandard',
            'calculate',
        ], $this->provider->basket->calls);
        self::assertNull($this->provider->resolvedPreferred, 'no storefront choice: the delivery set is resolved for user and basket');
    }

    /**
     * core's Order::validateDeliveryAddress() compares a request hash with the
     * user's current address and answers INVALIDDELADDRESSCHANGED when it is
     * missing. The Twig checkout posts it from the order page; the storefront
     * sets it itself; so does this provider - through the existing service,
     * not by writing superglobals here.
     */
    public function testLeavesTheDeliveryAddressHashWhereCoreValidatesIt(): void
    {
        $this->provider->userBaskets['ub-1'] = new FakeUserBasket('user-1', [new FakeUserBasketItem('art-1', 1.0)]);

        $this->provider->basketFor($this->request(basketId: 'ub-1'));

        self::assertSame('addr-hash-user-1', $this->addressHash->restored);
    }

    /**
     * graphql-storefront stores the shopper's `basketSetDeliveryMethod` choice
     * on the row (`OEGQL_DELIVERYMETHODID`). When it is there, it wins; the
     * provider only resolves one itself when the client never chose.
     */
    public function testAStorefrontChosenDeliverySetIsUsedAsIs(): void
    {
        $this->provider->userBaskets['ub-1'] = new FakeUserBasket(
            'user-1',
            [new FakeUserBasketItem('art-1', 1.0)],
            storefrontDeliverySetId: 'express'
        );

        $this->provider->basketFor($this->request(basketId: 'ub-1'));

        self::assertContains('shipping:express', $this->provider->basket->calls);
        self::assertSame('express', $this->provider->resolvedPreferred);
    }

    public function testAnUnknownUserIsRefused(): void
    {
        $this->provider->userBaskets['ub-1'] = new FakeUserBasket('ghost', [new FakeUserBasketItem('art-1', 1.0)]);

        try {
            $this->provider->basketFor($this->request(basketId: 'ub-1', userId: 'ghost'));
            self::fail('a basket whose owner cannot be loaded must be refused');
        } catch (ShopOrderException $e) {
            self::assertSame('user_not_found', $e->getErrorCode());
        }

        self::assertSame([], $this->provider->basket->calls);
    }

    private function request(?string $basketId, string $userId = 'user-1'): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'headless',
            userId: $userId,
            paymentId: 'oe_payments_stripe_wallet',
            basketId: $basketId,
        );
    }
}
