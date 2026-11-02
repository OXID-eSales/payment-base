<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\BuiltInSealPrimitives;
use OxidEsales\PaymentBase\ClientConfig\SealedChannelSealPrimitives;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * R5 of SEAL-1: the sealed client config (`paymentClientConfig`) works with and without GraphQL installed, and with and
 * without the sealed-channel module. Every case is a fresh PHP process in which the named namespaces cannot be
 * loaded at all (Support/seal-with-and-without.php), so "without" is real, whatever the shop has installed.
 */
final class SealedConfigWithAndWithoutTest extends TestCase
{
    private const GRAPHQL = 'OxidEsales\\GraphQL\\,TheCodingMachine\\GraphQLite\\,GraphQL\\';
    private const SEALED_CHANNEL = 'OxidEsales\\SealedChannel\\';

    /**
     * @return array<string, array{string, bool}> blocked namespaces, whether the module's primitives are expected
     */
    public static function combinations(): array
    {
        return [
            'as installed' => ['', true],
            'without GraphQL' => [self::GRAPHQL, true],
            'without the sealed-channel module' => [self::SEALED_CHANNEL, false],
            'without either' => [self::GRAPHQL . ',' . self::SEALED_CHANNEL, false],
        ];
    }

    #[DataProvider('combinations')]
    public function testTheConfigSealsInEveryCombination(string $blocked, bool $moduleExpected): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Support/seal-with-and-without.php', $blocked],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $output = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
        $exit = proc_close($process);

        $this->assertSame(0, $exit, $output);
        // the process (not this test) knows whether the shop's autoloader, which carries the module, was visible to it
        $this->assertMatchesRegularExpression('/^OK (\S+) installed=([01])$/', $output);
        preg_match('/^OK (\S+) installed=([01])$/', $output, $found);
        $expected = $moduleExpected && $found[2] === '1' ? SealedChannelSealPrimitives::class : BuiltInSealPrimitives::class;
        $this->assertSame($expected, $found[1]);
    }
}
