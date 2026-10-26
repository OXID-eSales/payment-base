<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use ReflectionProperty;

/**
 * STRP-176 — what the order-mail integration tests share: a container bound to
 * the connection the test transaction runs on, cleanup of stored mails on the
 * shared database, and the local Mailpit.
 *
 * Call setUpDeferredOrderMails() after parent::setUp() and
 * tearDownDeferredOrderMails() after parent::tearDown().
 */
trait DeferredOrderMailFixture
{
    private MailpitClient $mailpit;

    /** @var list<string> */
    private array $orderIds = [];

    private ?ContainerFactory $containerBeforeTest = null;

    private function setUpDeferredOrderMails(): void
    {
        // cleanupCaching() replaces the legacy DB object between tests while the
        // container keeps the connection it was built with. Rebuilt here, the
        // repositories write on the connection the test transaction runs on;
        // otherwise their rows commit outside it and outlive the rollback. The
        // previous container comes back in tearDown: later test classes
        // (OxidShopOrderServiceHeadlessDoubleSubmitTest) depend on the one they
        // would have had without these.
        $this->containerBeforeTest = $this->factoryInstance()->getValue();
        ContainerFactory::resetContainer();

        $this->mailpit = new MailpitClient();
        if (!$this->mailpit->isReachable()) {
            self::markTestSkipped('Mailpit (http://mailpit:8025) is not reachable');
        }
    }

    private function tearDownDeferredOrderMails(): void
    {
        unset($_POST['sDeliveryAddressMD5']);
        // After the rollback, so it also catches rows a future harness change
        // lets escape it: the table holds customer data, on a shared database.
        foreach ($this->orderIds as $orderId) {
            DatabaseProvider::getDb()->execute('DELETE FROM oe_payments_order_mail WHERE OXORDERID = ?', [$orderId]);
        }
        $this->factoryInstance()->setValue(null, $this->containerBeforeTest);
    }

    private function remember(string $orderId): string
    {
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function addressOf(User $user): string
    {
        return (string) $user->getFieldData('oxusername');
    }

    /**
     * @return list<array<string, string>>
     */
    private function storedMails(string $orderId): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
            "SELECT OXKIND, OXRECIPIENTS, OXSUBJECT, OXBODY FROM oe_payments_order_mail
             WHERE OXORDERID = :id ORDER BY FIELD(OXKIND, 'customer', 'owner')",
            [':id' => $orderId]
        );

        return $rows;
    }

    private function factoryInstance(): ReflectionProperty
    {
        return new ReflectionProperty(ContainerFactory::class, 'instance');
    }
}
