<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use RuntimeException;

/**
 * Doctrine DBAL implementation of PaymentCustomerRepositoryInterface.
 *
 * Uses the provider-agnostic osc_payment_customer table instead of
 * Stripe-specific osc_stripe_customer_mapping.
 *
 * LSP Compliance: Implements PaymentCustomerRepositoryInterface and can be
 * substituted with any other implementation.
 */
class DoctrinePaymentCustomerRepository implements PaymentCustomerRepositoryInterface
{
    private const TABLE_NAME = 'osc_payment_customer';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findByUserId(string $userId): ?array
    {
        try {
            $data = $this->connection->fetchAssociative(
                'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXUSERID = :userId',
                ['userId' => $userId]
            );

            if ($data === false) {
                return null;
            }

            return $data;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function save(array $data): void
    {
        try {
            $userId = $data['userId'] ?? null;

            if ($userId === null) {
                throw new RuntimeException('userId is required');
            }

            // Check if record exists
            $exists = $this->connection->fetchOne(
                'SELECT OXID FROM ' . self::TABLE_NAME . ' WHERE OXUSERID = :userId',
                ['userId' => $userId]
            );

            $dbData = [
                'OXUSERID' => $userId,
                'OXPAYMENTCUSTOMERID' => $data['paymentCustomerId'] ?? null,
                'OXDEFAULTPAYMENTMETHOD' => $data['defaultPaymentMethod'] ?? null,
                'OXSAVEDPAYMENTMETHODS' => isset($data['savedPaymentMethods'])
                    ? json_encode($data['savedPaymentMethods'])
                    : null,
                'OXBILLINGAGREEMENT' => $data['billingAgreement'] ?? null,
                'OXLASTPAYMENTDATE' => $data['lastPaymentDate'] ?? null,
                'OXUPDATED' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];

            if ($exists) {
                $this->connection->update(self::TABLE_NAME, $dbData, ['OXUSERID' => $userId]);
            } else {
                $dbData['OXID'] = $data['id'] ?? uniqid('cust_', true);
                $dbData['OXCREATED'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $this->connection->insert(self::TABLE_NAME, $dbData);
            }
        } catch (Exception $e) {
            throw new RuntimeException('Failed to save payment customer: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function findPaymentCustomerId(string $userId): ?string
    {
        try {
            $customerId = $this->connection->fetchOne(
                'SELECT OXPAYMENTCUSTOMERID FROM ' . self::TABLE_NAME . ' WHERE OXUSERID = :userId',
                ['userId' => $userId]
            );

            if ($customerId === false || $customerId === null) {
                return null;
            }

            return (string) $customerId;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function savePaymentCustomerId(string $userId, string $paymentCustomerId): void
    {
        try {
            // Check if record exists
            $exists = $this->connection->fetchOne(
                'SELECT OXID FROM ' . self::TABLE_NAME . ' WHERE OXUSERID = :userId',
                ['userId' => $userId]
            );

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            if ($exists) {
                $this->connection->update(
                    self::TABLE_NAME,
                    [
                        'OXPAYMENTCUSTOMERID' => $paymentCustomerId,
                        'OXUPDATED' => $now,
                    ],
                    ['OXUSERID' => $userId]
                );
            } else {
                $this->connection->insert(
                    self::TABLE_NAME,
                    [
                        'OXID' => uniqid('cust_', true),
                        'OXUSERID' => $userId,
                        'OXPAYMENTCUSTOMERID' => $paymentCustomerId,
                        'OXCREATED' => $now,
                        'OXUPDATED' => $now,
                    ]
                );
            }
        } catch (Exception $e) {
            throw new RuntimeException('Failed to save payment customer ID: ' . $e->getMessage(), 0, $e);
        }
    }
}
