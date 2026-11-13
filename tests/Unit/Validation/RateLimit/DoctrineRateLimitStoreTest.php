<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\RateLimit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\PaymentBase\Validation\RateLimit\DoctrineRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL): a rate limit that cannot count does not let the request through (it answered 0 = "under the limit").
 */
#[CoversClass(DoctrineRateLimitStore::class)]
final class DoctrineRateLimitStoreTest extends TestCase
{
    public function testADatabaseError_IsNotTakenAsUnderTheLimit(): void
    {
        $error = new DbalException('connection lost');
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException($error);
        $connection->method('fetchAssociative')->willThrowException($error);
        $connection->method('fetchOne')->willThrowException($error);
        $connection->method('insert')->willThrowException($error);
        $connection->method('update')->willThrowException($error);

        $store = new DoctrineRateLimitStore($connection, $this->createStub(IdempotencyRepositoryInterface::class));

        $this->expectException(DbalException::class);
        $store->increment('validate:k:1', 60);
    }
}
