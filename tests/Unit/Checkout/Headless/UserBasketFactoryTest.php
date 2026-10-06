<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use InvalidArgumentException;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactory;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use PHPUnit\Framework\TestCase;

final class RecordingUserBasket extends UserBasket
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $fields = [];

    public function __construct()
    {
    }

    public function setId($sOXID = null)
    {
        $this->calls[] = 'id:' . $sOXID;

        return $sOXID;
    }

    public function getId(): ?string
    {
        return 'ub-new';
    }

    public function __set(string $name, mixed $value): void
    {
        $this->fields[$name] = $value->value ?? $value;
    }

    public function save()
    {
        $this->calls[] = 'save';

        return 'ub-new';
    }

    public function addItemToBasket($sProductId = null, $dAmount = null, $aSel = null, $blOverride = false, $aPersParam = null)
    {
        $this->calls[] = sprintf('item:%s:%s', $sProductId, $dAmount);

        return null;
    }
}

final class TestableUserBasketFactory extends UserBasketFactory
{
    public RecordingUserBasket $row;

    public function __construct()
    {
        $this->row = new RecordingUserBasket();
    }

    protected function newUserBasket(): UserBasket
    {
        return $this->row;
    }
}

/**
 * Sprint 15 / S7 — an agent (ACP `create_checkout`) names items, not a
 * basket. The factory turns them into the same `oxuserbaskets` row the
 * GraphQL Storefront would have persisted, payment included, so the rest of
 * the headless path is one path.
 */
final class UserBasketFactoryTest extends TestCase
{
    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(UserBasketFactoryInterface::class, new TestableUserBasketFactory());
    }

    public function testPersistsOwnerPaymentAndItems(): void
    {
        $factory = new TestableUserBasketFactory();

        $basketId = $factory->create('user-1', [
            ['id' => 'art-1', 'quantity' => 2],
            ['id' => 'art-2', 'quantity' => 1],
        ], 'oe_payments_stripe_wallet');

        self::assertSame('ub-new', $basketId);
        self::assertSame('user-1', $factory->row->fields['oxuserbaskets__oxuserid']);
        self::assertSame(UserBasketFactory::TITLE, $factory->row->fields['oxuserbaskets__oxtitle']);
        self::assertSame(0, $factory->row->fields['oxuserbaskets__oxpublic']);
        self::assertSame('oe_payments_stripe_wallet', $factory->row->fields['oxuserbaskets__oegql_paymentid']);
        self::assertSame(['save', 'item:art-1:2', 'item:art-2:1'], array_values(array_filter(
            $factory->row->calls,
            static fn(string $call): bool => !str_starts_with($call, 'id:')
        )), 'the row is saved before items are attached to it');
    }

    public function testRefusesEmptyOrMalformedItems(): void
    {
        $factory = new TestableUserBasketFactory();

        foreach ([[], [['id' => 'art-1']], [['quantity' => 1]], [['id' => 'art-1', 'quantity' => 0]], [['id' => '', 'quantity' => 1]]] as $items) {
            try {
                $factory->create('user-1', $items, 'oe_payments_stripe_wallet');
                self::fail('items ' . json_encode($items) . ' must be refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $factory->row->calls, 'nothing is written for bad input');
    }
}
