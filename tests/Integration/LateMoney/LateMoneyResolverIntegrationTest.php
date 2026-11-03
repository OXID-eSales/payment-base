<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\LateMoney;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRiskFlagInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\LateMoney\LateMoneyKind;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolver;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGateways;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentStatus;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * VR-23 T1 - a contract the cleanup cancelled, then the provider reports the payment as paid: the refund is
 * booked once and survives the round trip through the real contract table, so a second delivery is a no-op.
 */
#[Group('integration')]
final class LateMoneyResolverIntegrationTest extends TestCase
{
    private Connection $connection;
    private DoctrineContractRepository $contracts;
    private string $contractId;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $this->contracts = new DoctrineContractRepository($this->connection, new NullLogger());
        $this->contractId = 'it_vr23_' . bin2hex(random_bytes(8));
        $contract = new PaymentContract(1, 'it_vr23_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 49.9, 'totalNet' => 41.9, 'totalVat' => 8.0, 'currency' => 'EUR',
        ]), $this->contractId);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_' . $this->contractId);
        $contract->transitionToPending();
        $contract->setProvider('mollie', 'tr_' . $this->contractId);
        $contract->cancel('not_finished_cleanup');
        $this->contracts->save($contract);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = :id', ['id' => $this->contractId]);
    }

    public function testAPaidWebhookOnACancelledContractRefundsOnceAcrossDeliveries(): void
    {
        $refunds = [];
        $gateway = $this->createMock(ProviderPaymentGatewayInterface::class);
        $gateway->method('provider')->willReturn('mollie');
        $gateway->method('statusOf')->willReturn(new ProviderPaymentStatus(ProviderPaymentState::Paid, 'tr_x', 49.9, 'EUR'));
        $gateway->method('refund')->willReturnCallback(static function (string $id, string $key) use (&$refunds): string {
            $refunds[] = [$id, $key];

            return 're_1';
        });
        $resolver = new LateMoneyResolver(
            new ProviderPaymentGateways([$gateway]),
            $this->contracts,
            $this->createStub(PaymentAtRiskFlagInterface::class),
            new NullLogger(),
            $this->createStub(ThrowableSummaryInterface::class),
        );

        $first = $resolver->resolve($this->contracts->findById($this->contractId) ?? self::fail("contract"), 'tr_x');
        $second = $resolver->resolve($this->contracts->findById($this->contractId) ?? self::fail("contract"), 'tr_x');

        self::assertSame(LateMoneyKind::Refunded, $first->kind);
        self::assertSame(LateMoneyKind::AlreadySettled, $second->kind);
        self::assertSame([['tr_x', 'late:tr_x']], $refunds);
        self::assertTrue($this->contracts->findById($this->contractId)?->getState()->isCancelled(), 'the contract stays cancelled');
    }
}
