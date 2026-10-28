<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * payment-base must keep working, and activate, on a shop without oxid-admin-graphql. Three things guarantee it:
 * every service of the admin API's own is reached only through `@?`; every class of the admin payment API that
 * the container reflects loads in a process where none of the optional packages exist; and the only way in
 * (NamespaceMapper) maps nothing of it without the package (NamespaceMapperTest).
 */
final class AdminApiIsOptionalTest extends TestCase
{
    private const ADMIN_API = 'OxidEsales\\GraphQL\\Admin\\';

    public function testEveryReferenceToAServiceOfTheAdminApiIsOptional(): void
    {
        $services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $hard = [];
        array_walk_recursive($services, static function (mixed $value, mixed $key) use (&$hard): void {
            if (is_string($value) && str_starts_with($value, '@' . self::ADMIN_API)) {
                $hard[] = $value;
            }
        });

        self::assertSame([], $hard, 'a hard reference to an admin API service breaks the compile without it: use @?');
        self::assertNotEmpty(
            array_filter(array_keys($services), static fn (string $id): bool => str_contains($id, 'GraphQL\\Admin\\')),
            'the admin payment services are registered',
        );
    }

    public function testNoServiceIsDefinedForAClassOfTheAdminApiItself(): void
    {
        $services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        foreach (array_keys($services) as $id) {
            self::assertStringStartsNotWith(self::ADMIN_API, (string) $id, 'payment-base must not redefine a service of the admin API');
        }
    }

    public function testEveryAdminPaymentServiceClassLoadsWhereNoOptionalPackageExists(): void
    {
        $script = __DIR__ . '/Support/reflect-services-without-admin.php';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertMatchesRegularExpression('/^OK (\d+)$/m', $output, $output);
        preg_match('/^OK (\d+)$/m', $output, $matches);
        self::assertGreaterThanOrEqual(15, (int) $matches[1], 'the admin payment services and the provider-facing interfaces were actually reflected');
    }
}
