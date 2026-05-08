<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentBase\Contract\PaymentCustomer;

/**
 * Doctrine DBAL implementation of PaymentCustomerRepositoryInterface.
 *
 * Sprint 45: Stripe Customer lifecycle.
 */
class DoctrinePaymentCustomerRepository implements PaymentCustomerRepositoryInterface
{
    private const TABLE = 'oe_payments_customer';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function save(PaymentCustomer $customer): void
    {
        $data = $this->prepareData($customer);

        try {
            $exists = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXID = :id',
                ['id' => $customer->getId()]
            );

            if ($exists > 0) {
                $this->connection->update(self::TABLE, $data, ['OXID' => $customer->getId()]);
                return;
            }

            $this->connection->insert(self::TABLE, $data);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findByUserId(string $userId): ?PaymentCustomer
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE OXUSERID = :userId';

        try {
            $data = $this->connection->fetchAssociative($sql, ['userId' => $userId]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateRecord($data);
        } catch (Exception $e) {
            return null;
        }
    }

    public function findByPaymentCustomerId(string $paymentCustomerId): ?PaymentCustomer
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE OXPAYMENTCUSTOMERID = :paymentCustomerId';

        try {
            $data = $this->connection->fetchAssociative($sql, ['paymentCustomerId' => $paymentCustomerId]);

            if ($data === false) {
                return null;
            }

            return $this->hydrateRecord($data);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareData(PaymentCustomer $customer): array
    {
        return [
            'OXID' => $customer->getId(),
            'OXUSERID' => $customer->getUserId(),
            'OXPAYMENTCUSTOMERID' => $customer->getPaymentCustomerId(),
            'OXDEFAULTPAYMENTMETHOD' => $customer->getDefaultPaymentMethod(),
            'OXSAVEDPAYMENTMETHODS' => $customer->getSavedPaymentMethods(),
            'OXBILLINGAGREEMENT' => $customer->getBillingAgreement() ? 1 : 0,
            'OXLASTPAYMENTDATE' => $customer->getLastPaymentDate()?->format('Y-m-d H:i:s'),
            'OXCREATED' => $customer->getCreatedAt()->format('Y-m-d H:i:s'),
            'OXUPDATED' => $customer->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateRecord(array $data): PaymentCustomer
    {
        /** @phpstan-ignore-next-line */
        $id = is_string($data['OXID']) ? $data['OXID'] : (string) ($data['OXID'] ?? '');
        /** @phpstan-ignore-next-line */
        $userId = is_string($data['OXUSERID']) ? $data['OXUSERID'] : (string) ($data['OXUSERID'] ?? '');
        /** @phpstan-ignore-next-line */
        $createdAt = new DateTimeImmutable(is_string($data['OXCREATED']) ? $data['OXCREATED'] : 'now');
        /** @phpstan-ignore-next-line */
        $updatedAt = new DateTimeImmutable(is_string($data['OXUPDATED']) ? $data['OXUPDATED'] : 'now');

        $record = new PaymentCustomer($id, $userId, $createdAt, $updatedAt);

        if (isset($data['OXPAYMENTCUSTOMERID']) && is_string($data['OXPAYMENTCUSTOMERID'])) {
            $record->setPaymentCustomerId($data['OXPAYMENTCUSTOMERID']);
        }

        if (isset($data['OXDEFAULTPAYMENTMETHOD']) && is_string($data['OXDEFAULTPAYMENTMETHOD'])) {
            $record->setDefaultPaymentMethod($data['OXDEFAULTPAYMENTMETHOD']);
        }

        if (isset($data['OXSAVEDPAYMENTMETHODS']) && is_string($data['OXSAVEDPAYMENTMETHODS'])) {
            $record->setSavedPaymentMethods($data['OXSAVEDPAYMENTMETHODS']);
        }

        if (isset($data['OXBILLINGAGREEMENT'])) {
            $record->setBillingAgreement((bool) $data['OXBILLINGAGREEMENT']);
        }

        if (isset($data['OXLASTPAYMENTDATE']) && is_string($data['OXLASTPAYMENTDATE'])) {
            $record->setLastPaymentDate(new DateTimeImmutable($data['OXLASTPAYMENTDATE']));
        }

        return $record;
    }
}
