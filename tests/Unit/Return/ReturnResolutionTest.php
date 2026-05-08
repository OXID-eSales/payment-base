<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Return;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReturnResolutionTest extends TestCase
{
    public function testAuthorizedFactorySetsOutcomeAndRequiresCaptureTrue(): void
    {
        $r = ReturnResolution::authorized('auth_1', 'ord_1', 42.0, 'EUR');

        self::assertSame(ReturnResolution::OUTCOME_AUTHORIZED, $r->outcome);
        self::assertTrue($r->requiresCapture);
        self::assertSame('auth_1', $r->authorizationId);
        self::assertSame('ord_1', $r->providerOrderId);
        self::assertSame(42.0, $r->amount);
        self::assertSame('EUR', $r->currency);
        self::assertTrue($r->isSuccessful());
    }

    public function testReadyToCommitFactorySetsRequiresCaptureFalse(): void
    {
        $r = ReturnResolution::readyToCommit('auth_1', 'ord_1', 10.0, 'EUR');
        self::assertFalse($r->requiresCapture);
        self::assertSame(ReturnResolution::OUTCOME_READY_TO_COMMIT, $r->outcome);
    }

    public function testAlreadyProcessedFactoryIsSuccessful(): void
    {
        $r = ReturnResolution::alreadyProcessed('ord_1');
        self::assertSame(ReturnResolution::OUTCOME_ALREADY_PROCESSED, $r->outcome);
        self::assertTrue($r->isSuccessful());
        self::assertNull($r->authorizationId);
    }

    public function testFailedCarriesErrorCodeAndMessage(): void
    {
        $r = ReturnResolution::failed('invalid_status', 'PSP rejected', 'ord_1');
        self::assertSame('failed', $r->outcome);
        self::assertSame('invalid_status', $r->errorCode);
        self::assertSame('PSP rejected', $r->errorMessage);
        self::assertFalse($r->isSuccessful());
    }

    public function testSuccessfulOutcomeRequiresAuthorizationId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReturnResolution(
            outcome: ReturnResolution::OUTCOME_AUTHORIZED,
            authorizationId: null,
            providerOrderId: 'ord_1',
            amount: 1.0,
            currency: 'EUR',
            requiresCapture: false,
        );
    }

    public function testIsSuccessfulMatrix(): void
    {
        $base = static fn(string $outcome): ReturnResolution => new ReturnResolution(
            outcome: $outcome,
            authorizationId: 'auth_x',
            providerOrderId: 'ord_x',
            amount: 1.0,
            currency: 'EUR',
            requiresCapture: false,
        );

        self::assertTrue($base(ReturnResolution::OUTCOME_AUTHORIZED)->isSuccessful());
        self::assertTrue($base(ReturnResolution::OUTCOME_READY_TO_COMMIT)->isSuccessful());
        self::assertTrue(ReturnResolution::alreadyProcessed('ord')->isSuccessful());
        self::assertFalse(ReturnResolution::failed('x', 'y')->isSuccessful());
    }

    public function testNoPspSpecificTypeLeaks(): void
    {
        $reflection = new ReflectionClass(ReturnResolution::class);
        foreach ($reflection->getProperties() as $prop) {
            $type = $prop->getType();
            if ($type === null) {
                continue;
            }
            $typeName = (string) $type;
            self::assertStringNotContainsString('Stripe', $typeName, 'Stripe leaked into DTO');
            self::assertStringNotContainsString('PayPal', $typeName, 'PayPal leaked into DTO');
        }
    }
}
