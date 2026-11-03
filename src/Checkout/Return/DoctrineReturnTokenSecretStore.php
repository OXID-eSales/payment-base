<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * `oe_payments_secrets` rows of purpose `return_token`. The migration creates the first one; a
 * rotation inserts a newer row, retiring one deletes it.
 */
class DoctrineReturnTokenSecretStore implements ReturnTokenSecretStoreInterface
{
    public const TABLE = 'oe_payments_secrets';
    public const PURPOSE = 'return_token';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function current(): ReturnTokenSecret
    {
        $row = $this->connection->fetchAssociative(
            'SELECT OXID, OXSECRET FROM ' . self::TABLE . ' WHERE OXPURPOSE = :purpose
             ORDER BY OXCREATEDAT DESC, OXID DESC LIMIT 1',
            ['purpose' => self::PURPOSE]
        );
        if ($row === false) {
            throw new RuntimeException(
                'No return-token secret in ' . self::TABLE . '; run the payment-base migrations.'
            );
        }

        return new ReturnTokenSecret((string) $row['OXID'], (string) $row['OXSECRET']);
    }

    public function find(string $keyId): ?ReturnTokenSecret
    {
        $secret = $this->connection->fetchOne(
            'SELECT OXSECRET FROM ' . self::TABLE . ' WHERE OXID = :id AND OXPURPOSE = :purpose',
            ['id' => $keyId, 'purpose' => self::PURPOSE]
        );

        return is_string($secret) ? new ReturnTokenSecret($keyId, $secret) : null;
    }
}
