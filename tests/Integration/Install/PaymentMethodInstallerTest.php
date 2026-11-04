<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Install;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\PaymentBase\Install\PaymentMethodInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Runs against THROWAWAY payment ids only (never a live provider row).
 */
#[CoversClass(PaymentMethodInstaller::class)]
#[Group('integration')]
#[Group('database')]
final class PaymentMethodInstallerTest extends TestCase
{
    private Connection $connection;
    private PaymentMethodInstaller $installer;
    /** @var list<string> */
    private array $paymentIdsToClean = [];

    protected function setUp(): void
    {
        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->connection = $container->get(ConnectionProviderInterface::class)->get();
            $this->installer = new PaymentMethodInstaller($container->get(QueryBuilderFactoryInterface::class));
        } catch (Throwable $e) {
            self::markTestSkipped('OXID container unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paymentIdsToClean as $paymentId) {
            $this->connection->executeStatement('DELETE FROM oxpayments WHERE OXID = :id', ['id' => $paymentId]);
            $this->connection->executeStatement(
                'DELETE FROM oxobject2payment WHERE OXPAYMENTID = :id',
                ['id' => $paymentId],
            );
        }
    }

    public function testCreatesThePaymentRowWithTranslationsAndAssignsEveryActiveDeliverySet(): void
    {
        $paymentId = $this->throwawayPaymentId();

        $this->installer->ensurePaymentMethods([$paymentId => $this->definition()]);

        $row = $this->connection->fetchAssociative(
            'SELECT OXACTIVE, OXFROMAMOUNT, OXTOAMOUNT, OXADDSUMTYPE, OXDESC, OXDESC_1 FROM oxpayments WHERE OXID = :id',
            ['id' => $paymentId],
        );
        self::assertIsArray($row, 'the payment row was created');
        self::assertSame(1, (int) $row['OXACTIVE']);
        self::assertSame(1.0, (float) $row['OXFROMAMOUNT']);
        self::assertSame(5000.0, (float) $row['OXTOAMOUNT']);
        self::assertSame('abs', $row['OXADDSUMTYPE']);
        self::assertContains('Testzahlart', [$row['OXDESC'], $row['OXDESC_1']]);
        self::assertContains('Test payment', [$row['OXDESC'], $row['OXDESC_1']]);

        $activeDeliverySets = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oxdeliveryset WHERE OXACTIVE = 1');
        $assignments = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM oxobject2payment WHERE OXPAYMENTID = :id AND OXTYPE = 'oxdelset'",
            ['id' => $paymentId],
        );
        self::assertSame($activeDeliverySets, $assignments, 'one assignment per active delivery set');
    }

    public function testEnsureIsIdempotentAndDoesNotClobberAdminEdits(): void
    {
        $paymentId = $this->throwawayPaymentId();
        $this->installer->ensurePaymentMethods([$paymentId => $this->definition()]);
        $this->connection->executeStatement(
            'UPDATE oxpayments SET OXACTIVE = 0 WHERE OXID = :id',
            ['id' => $paymentId],
        );
        $assignmentsBefore = $this->countAssignments($paymentId);

        $this->installer->ensurePaymentMethods([$paymentId => $this->definition()]);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT OXACTIVE FROM oxpayments WHERE OXID = :id', ['id' => $paymentId])
        );
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oxpayments WHERE OXID = :id', ['id' => $paymentId])
        );
        self::assertSame($assignmentsBefore, $this->countAssignments($paymentId));
    }

    private function countAssignments(string $paymentId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM oxobject2payment WHERE OXPAYMENTID = :id',
            ['id' => $paymentId],
        );
    }

    private function throwawayPaymentId(): string
    {
        $paymentId = substr('pbinstalltest' . bin2hex(random_bytes(6)), 0, 32);
        $this->paymentIdsToClean[] = $paymentId;

        return $paymentId;
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'defaulton' => true,
            'constraints' => ['oxfromamount' => 1.0, 'oxtoamount' => 5000.0, 'oxaddsumtype' => 'abs'],
            'descriptions' => [
                'de' => ['desc' => 'Testzahlart', 'longdesc' => 'Langtext DE'],
                'en' => ['desc' => 'Test payment', 'longdesc' => 'Long text EN'],
            ],
        ];
    }
}
