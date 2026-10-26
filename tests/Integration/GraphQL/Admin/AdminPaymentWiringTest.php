<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL\Admin;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Admin\Authorization\Service\RightsCatalogue;
use OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership;
use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderCancelled;
use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderChangeGuard;
use OxidEsales\GraphQL\Admin\Order\DataType\OrderChangeKind;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\GraphQL\Service\NamespaceMapper;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\AdminSchemaProbe;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The admin payment API against the real shop container with oxid-admin-graphql installed: the rights reach the
 * catalogue, the admin API's events reach the subscribers, the schema carries the new field and mutations.
 * Skipped where the admin API is not installed (payment-base then runs without any of this).
 */
#[Group('integration')]
final class AdminPaymentWiringTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(RightsCatalogue::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
    }

    public function tearDown(): void
    {
        // The subscribers read through the container's own connection, so the fixtures are written through it
        // too (committed). Delete them after the test transaction has ended: inside it the delete would be
        // rolled back with it.
        parent::tearDown();
        $connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $connection->executeStatement("DELETE FROM oe_payments_contract WHERE OXORDERID LIKE 'a10-%'");
    }

    public function testRightsCatalogue_ContainsThePaymentRightsOfThisModule(): void
    {
        /** @var RightsCatalogue $catalogue */
        $catalogue = $this->service(RightsCatalogue::class);

        foreach (['ADMIN_PAYMENT_VIEW', 'ADMIN_PAYMENT_CAPTURE', 'ADMIN_PAYMENT_REFUND', 'ADMIN_PAYMENT_CONFIG'] as $right) {
            self::assertTrue($catalogue->has($right), $right . ' must be in the catalogue');
        }
        self::assertSame('payment', $catalogue->find('ADMIN_PAYMENT_REFUND')?->domain());
        self::assertSame('rights.payment_refund', $catalogue->find('ADMIN_PAYMENT_REFUND')?->descriptionKey());
    }

    public function testNamespaceMapper_InTheRealShop_MapsTheAdminPaymentApi(): void
    {
        $mapper = new NamespaceMapper();

        self::assertArrayHasKey('OxidEsales\\PaymentBase\\GraphQL\\Admin\\Controller', $mapper->getControllerNamespaceMapping());
        self::assertArrayHasKey('OxidEsales\\PaymentBase\\GraphQL\\Admin\\Relation', $mapper->getTypeNamespaceMapping());
    }

    public function testSchema_HasPaymentContractOnAdminOrderAndTheThreeMutations(): void
    {
        $schema = AdminSchemaProbe::sdl();

        self::assertMatchesRegularExpression('/type AdminOrder \{[^}]*paymentContract: AdminPaymentContract/s', $schema);
        self::assertMatchesRegularExpression('/type AdminPaymentContract \{[^}]*amountAuthorised: Float![^}]*transactions: \[AdminPaymentTransaction!\]!/s', $schema);
        self::assertMatchesRegularExpression('/type AdminPaymentTransaction \{[^}]*providerReference: String/s', $schema);
        self::assertStringContainsString('adminPaymentCapture(orderId: ID!, amount: Float = null, idempotencyKey: String = null): AdminPaymentOperationPayload!', $schema);
        self::assertStringContainsString('adminPaymentRefund(orderId: ID!, amount: Float!, reason: String = null, idempotencyKey: String = null): AdminPaymentOperationPayload!', $schema);
        self::assertStringContainsString('adminPaymentVoid(orderId: ID!, idempotencyKey: String = null): AdminPaymentOperationPayload!', $schema);
    }

    public function testOwnershipEvent_RealDispatcher_PayPalRowIsClaimed_AShopRowIsNot(): void
    {
        $paypal = new AdminPaymentOwnership('oe_payments_paypal');
        $invoice = new AdminPaymentOwnership('oxidinvoice');

        $this->dispatcher()->dispatch($paypal);
        $this->dispatcher()->dispatch($invoice);

        self::assertSame('paypal', $paypal->claimed()?->provider);
        self::assertSame(['title', 'description'], $paypal->claimed()?->readOnlyFields);
        self::assertNull($invoice->claimed());
    }

    public function testChangeGuardEvent_RealDispatcher_RefusesAnItemChangeAfterCapture(): void
    {
        $this->saveContract('a10-guard-order', captured: 25.0);
        $items = new AdminOrderChangeGuard('a10-guard-order', 1, 'oe_payments_paypal', 'tx', OrderChangeKind::Items);
        $addresses = new AdminOrderChangeGuard('a10-guard-order', 1, 'oe_payments_paypal', 'tx', OrderChangeKind::Addresses);

        $this->dispatcher()->dispatch($items);
        $this->dispatcher()->dispatch($addresses);

        self::assertTrue($items->isRefused());
        self::assertFalse($addresses->isRefused());
    }

    public function testChangeGuardEvent_NoCapture_AllowsTheChange(): void
    {
        $this->saveContract('a10-open-order', captured: null);
        $guard = new AdminOrderChangeGuard('a10-open-order', 1, 'oe_payments_paypal', 'tx', OrderChangeKind::Items);

        $this->dispatcher()->dispatch($guard);

        self::assertFalse($guard->isRefused());
    }

    public function testCancelEvent_RealDispatcher_NeverMovesMoney(): void
    {
        $contractId = $this->saveContract('a10-cancel-order', captured: 40.0);

        $this->dispatcher()->dispatch(new AdminOrderCancelled('a10-cancel-order', 1, 'oe_payments_paypal', 'tx'));

        $contract = $this->contracts()->findById($contractId);
        self::assertSame(40.0, $contract?->getCapturedAmount());
        self::assertNull($contract?->getRefundedAmount());
        self::assertSame('fulfilled', $contract?->getStateValue());
    }

    private function saveContract(string $orderId, ?float $captured): string
    {
        $contract = new PaymentContract(1, 'a10-user', BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider('paypal', 'a10-prov');
        $contract->authorize();
        $contract->captureAuthorization();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);
        if ($captured !== null) {
            $contract->setCapturedAmount($captured);
            $contract->fulfill();
        }
        $this->contracts()->save($contract);

        return (string) $contract->getId();
    }

    private function contracts(): ContractRepositoryInterface
    {
        /** @var ContractRepositoryInterface $contracts */
        $contracts = $this->service(ContractRepositoryInterface::class);

        return $contracts;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $this->service(EventDispatcherInterface::class);

        return $dispatcher;
    }

    private function service(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
