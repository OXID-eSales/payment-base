<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Service;

use OxidEsales\GraphQL\Base\Service\Token;
use OxidEsales\PaymentBase\GraphQL\Service\BearerTokenIdReader;
use PHPUnit\Framework\TestCase;

final class BearerTokenIdReaderTest extends TestCase
{
    public function testReadsTheTokenidClaimOfTheBearerJwt(): void
    {
        $token = new class extends Token {
            public function __construct()
            {
            }

            public function getTokenClaim(string $claim, mixed $default = null): mixed
            {
                return $claim === 'tokenid' ? 'abc-123' : $default;
            }
        };

        self::assertSame('abc-123', (new BearerTokenIdReader($token))->tokenId());
    }

    public function testAnAbsentClaimIsAnEmptyString(): void
    {
        $token = new class extends Token {
            public function __construct()
            {
            }

            public function getTokenClaim(string $claim, mixed $default = null): mixed
            {
                return $default;
            }
        };

        self::assertSame('', (new BearerTokenIdReader($token))->tokenId());
    }

    public function testWithoutGraphqlBaseTheIdIsEmpty(): void
    {
        self::assertSame('', (new BearerTokenIdReader(null))->tokenId());
    }
}
