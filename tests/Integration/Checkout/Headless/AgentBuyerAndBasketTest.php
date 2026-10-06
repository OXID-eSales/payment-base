<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout\Headless;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 15 / S7 against the real shop: an agent's buyer becomes a guest
 * account once and is reused afterwards; an agent's items become a user
 * basket row with items and the payment; the opening service resolves from
 * the container.
 */
#[Group('integration')]
final class AgentBuyerAndBasketTest extends IntegrationTestCase
{
    use CheckoutFixture;

    public function testABuyerBecomesAGuestAccountOnceAndIsReused(): void
    {
        $email = 'agent_' . uniqid() . '@example.invalid';
        /** @var GuestUserResolverInterface $resolver */
        $resolver = ContainerFactory::getInstance()->getContainer()->get(GuestUserResolverInterface::class);

        $userId = $resolver->resolve(
            ['email' => $email, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['line_one' => 'Analytical Way 1', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE']
        );

        $user = oxNew(User::class);
        self::assertTrue($user->load($userId));
        self::assertSame($email, $user->getFieldData('oxusername'));
        self::assertSame('Ada', $user->getFieldData('oxfname'));
        self::assertSame('Berlin', $user->getFieldData('oxcity'));
        self::assertSame(self::GERMANY, $user->getFieldData('oxcountryid'), 'ISO DE maps to the shop\'s Germany');
        self::assertSame('1', (string) $user->getFieldData('oxactive'));

        self::assertSame($userId, $resolver->resolve(['email' => strtoupper($email)], null), 'second time: the same account');
    }

    public function testItemsBecomeAUserBasketRowWithThePayment(): void
    {
        $user = $this->createFixtureUser('agent');
        $article = $this->createFixtureArticle();
        /** @var UserBasketFactoryInterface $factory */
        $factory = ContainerFactory::getInstance()->getContainer()->get(UserBasketFactoryInterface::class);

        $basketId = $factory->create($user->getId(), [['id' => $article->getId(), 'quantity' => 3]], 'oe_payments_stripe_wallet');

        $row = oxNew(UserBasket::class);
        self::assertTrue($row->load($basketId));
        self::assertSame($user->getId(), $row->getFieldData('oxuserid'));
        $items = array_values($row->getItems());
        self::assertCount(1, $items, 'one line for the one product');
        self::assertSame(3.0, (float) $items[0]->getFieldData('oxamount'));
        self::assertSame($article->getId(), $items[0]->getFieldData('oxartid'));
        self::assertSame('oe_payments_stripe_wallet', $row->getFieldData('oegql_paymentid'));
    }

    public function testTheOpeningServiceResolvesFromTheContainer(): void
    {
        self::assertInstanceOf(
            ContractOpeningServiceInterface::class,
            ContainerFactory::getInstance()->getContainer()->get(ContractOpeningServiceInterface::class)
        );
    }
}
