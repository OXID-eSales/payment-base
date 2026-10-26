<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\EventSystem;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use OxidEsales\PaymentBase\Tests\Integration\Support\DeferredOrderMailFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * STRP-176 — when the contract commits (the PSP confirmed the payment), the
 * order mails stored at early order creation go out: once, in the language
 * of the order, with the totals the customer paid. Through the real event
 * dispatcher, the real handler wiring and real mail into Mailpit.
 */
#[Group('integration')]
#[Group('mailpit')]
final class OrderConfirmationMailOnCommitTest extends IntegrationTestCase
{
    use CheckoutFixture;
    use DeferredOrderMailFixture;

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpDeferredOrderMails();
    }

    public function tearDown(): void
    {
        Registry::getLang()->setBaseLanguage(0);
        Registry::getLang()->setTplLanguage(0);
        parent::tearDown();
        $this->tearDownDeferredOrderMails();
    }

    public function testCommit_SendsOneCustomerAndOneOwnerMailAndDropsTheStoredCopies(): void
    {
        $user = $this->checkoutUser('commit');
        $orderId = $this->remember($this->createOrderFor($user));
        $orderNr = (string) $this->loadOrder($orderId)->getFieldData('oxordernr');

        $this->commit($orderId);

        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 1));
        self::assertStringContainsString('(#' . $orderNr . ')', $this->mailpit->messagesTo($this->addressOf($user))[0]['subject']);
        self::assertTrue($this->ownerMailArrivedFor($orderNr), 'the shop owner gets the order mail too');
        self::assertSame([], $this->storedMails($orderId));
    }

    public function testCommitSeenTwice_SendsTheMailOnce(): void
    {
        // The return leg and the webhook leg, or a replayed webhook.
        $user = $this->checkoutUser('twice');
        $orderId = $this->remember($this->createOrderFor($user));

        $this->commit($orderId);
        $this->commit($orderId);

        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 2, 4000));
    }

    public function testCommitOfAStornoedOrder_SendsNothing(): void
    {
        // A late payment can commit the contract of an order the cleanup
        // already cancelled - straight SQL, so the stored rows are still there.
        $user = $this->checkoutUser('storno');
        $orderId = $this->remember($this->createOrderFor($user));
        DatabaseProvider::getDb()->execute('UPDATE oxorder SET OXSTORNO = 1 WHERE OXID = ?', [$orderId]);

        $this->commit($orderId);

        self::assertSame(0, $this->mailpit->waitForCountTo($this->addressOf($user), 1, 3000));
    }

    public function testCommitInAnotherLanguage_SendsTheMailInTheOrdersLanguage(): void
    {
        // The webhook request runs in the shop's default language; the mail
        // was rendered in the customer's, at order creation.
        Registry::getLang()->setBaseLanguage(1);
        Registry::getLang()->setTplLanguage(1);
        $user = $this->checkoutUser('english');
        $orderId = $this->remember($this->createOrderFor($user));
        Registry::getLang()->setBaseLanguage(0);
        Registry::getLang()->setTplLanguage(0);

        $this->commit($orderId);

        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 1));
        self::assertStringStartsWith('Your order at', $this->mailpit->messagesTo($this->addressOf($user))[0]['subject']);
    }

    public function testCommit_MailShowsEveryVatRateOfTheBasket(): void
    {
        // The case the spike could not cover with existing orders.
        $user = $this->createFixtureUser('strp176_vat');
        $this->createFixturePayment();
        $basket = oxNew(Basket::class);
        $basket->setBasketUser($user);
        $basket->addToBasket($this->articleWithVat(19.0)->getId(), 1);
        $basket->addToBasket($this->articleWithVat(7.0)->getId(), 2);
        $basket->setPayment(self::PAYMENT_ID);
        $basket->setShipping(self::DELIVERY_SET);
        $basket->calculateBasket();
        Registry::getSession()->setBasket($basket);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();
        $orderId = $this->remember($this->createOrderFor($user));
        $total = Registry::getLang()->formatCurrency(
            (float) $this->loadOrder($orderId)->getFieldData('oxtotalordersum')
        );

        $this->commit($orderId);

        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 1));
        $text = $this->mailpit->textOf($this->mailpit->messagesTo($this->addressOf($user))[0]['id']);
        self::assertStringContainsString('19%', $text);
        self::assertStringContainsString('7%', $text);
        self::assertStringContainsString($total, $text, 'the mail shows the total that was charged');
    }

    private function commit(string $orderId): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'strp176-user', $snapshot, 'strp176-contract-' . $orderId);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = ContainerFactory::getInstance()->getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new ContractCommittedEvent($contract, new EventContext(), $orderId));
    }

    private function ownerMailArrivedFor(string $orderNr): bool
    {
        $deadline = microtime(true) + 10;
        do {
            foreach ($this->mailpit->messagesTo('order@myoxideshop.com') as $message) {
                if (str_contains($message['subject'], '(#' . $orderNr . ')')) {
                    return true;
                }
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function checkoutUser(string $suffix): User
    {
        $user = $this->createFixtureUser('strp176_' . $suffix);
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();

        return $user;
    }

    private function articleWithVat(float $vat): Article
    {
        $article = oxNew(Article::class);
        $article->setId('e2e_sa_art_' . substr(md5(uniqid('', true)), 0, 8));
        $article->oxarticles__oxshopid = new Field(Registry::getConfig()->getShopId(), Field::T_RAW);
        $article->oxarticles__oxactive = new Field(1, Field::T_RAW);
        $article->oxarticles__oxartnum = new Field('E2E-SA-VAT', Field::T_RAW);
        $article->oxarticles__oxtitle = new Field('VAT ' . $vat . ' fixture article', Field::T_RAW);
        $article->oxarticles__oxprice = new Field(10.0, Field::T_RAW);
        $article->oxarticles__oxvat = new Field($vat, Field::T_RAW);
        $article->oxarticles__oxstock = new Field(100, Field::T_RAW);
        $article->oxarticles__oxstockflag = new Field(1, Field::T_RAW);
        $article->save();

        return $article;
    }
}
