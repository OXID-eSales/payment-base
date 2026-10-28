<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use ReflectionProperty;

/**
 * STRP-176 — what the order-mail integration tests share: a container bound to
 * the connection the test transaction runs on, a real contract row for every
 * order (the mails wait on it), cleanup on the shared database, and the local
 * Mailpit.
 *
 * Needs CheckoutFixture. Call setUpDeferredOrderMails() after parent::setUp()
 * and tearDownDeferredOrderMails() after parent::tearDown().
 */
trait DeferredOrderMailFixture
{
    private MailpitClient $mailpit;

    /** @var list<string> */
    private array $contractIds = [];

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
        // lets escape it: the mails hold customer data, on a shared database.
        foreach ($this->contractIds as $contractId) {
            DatabaseProvider::getDb()->execute('DELETE FROM oe_payments_contract WHERE OXID = ?', [$contractId]);
        }
        $this->factoryInstance()->setValue(null, $this->containerBeforeTest);
    }

    /**
     * What a PSP checkout does: a contract first, then the order that names it,
     * then the contract learns its order (EarlyOrderCreationHandler).
     *
     * @return array{0: string, 1: string} order id, contract id
     */
    private function createOrderOnContract(User $user): array
    {
        $contractId = 'strp176-' . substr(md5(uniqid('', true)), 0, 24);
        $this->contractIds[] = $contractId;
        $this->contracts()->save(new PaymentContract(1, (string) $user->getId(), BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]), $contractId));

        Registry::getSession()->setVariable('sess_challenge', md5(uniqid((string) mt_rand(), true)));
        $orderId = $this->orderService()->createOrder(new CreateOrderRequest(
            sessionId: (string) Registry::getSession()->getId(),
            userId: (string) $user->getId(),
            paymentId: self::PAYMENT_ID,
            metadata: ['contract_id' => $contractId],
            initialStatus: 'NOT_FINISHED',
        ))->orderId;
        DatabaseProvider::getDb()->execute(
            'UPDATE oe_payments_contract SET OXORDERID = ? WHERE OXID = ?',
            [$orderId, $contractId]
        );

        return [$orderId, $contractId];
    }

    private function orderService(): ShopOrderServiceInterface
    {
        /** @var ShopOrderServiceInterface $service */
        $service = ContainerFactory::getInstance()->getContainer()->get(ShopOrderServiceInterface::class);

        return $service;
    }

    private function contracts(): ContractRepositoryInterface
    {
        /** @var ContractRepositoryInterface $contracts */
        $contracts = ContainerFactory::getInstance()->getContainer()->get(ContractRepositoryInterface::class);

        return $contracts;
    }

    private function addressOf(User $user): string
    {
        return (string) $user->getFieldData('oxusername');
    }

    /**
     * @return array{state: ?string, mails: list<array<string, mixed>>, sentAt: ?string}
     */
    private function orderMailsOn(string $contractId): array
    {
        /** @var array<string, ?string> $row */
        $row = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getRow(
            'SELECT OXORDERMAILSTATE, OXORDERMAILS, OXORDERMAILSENTAT FROM oe_payments_contract WHERE OXID = :id',
            [':id' => $contractId]
        );
        $mails = json_decode((string) ($row['OXORDERMAILS'] ?? ''), true);

        return [
            'state' => $row['OXORDERMAILSTATE'] ?? null,
            'mails' => is_array($mails) ? $mails : [],
            'sentAt' => $row['OXORDERMAILSENTAT'] ?? null,
        ];
    }

    private function factoryInstance(): ReflectionProperty
    {
        return new ReflectionProperty(ContainerFactory::class, 'instance');
    }
}
