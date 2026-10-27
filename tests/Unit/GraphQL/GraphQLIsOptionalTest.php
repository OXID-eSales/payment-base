<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * GraphQL is an optional shop feature: payment-base must work with AND without graphql-base, graphql-storefront,
 * GraphQLite, webonyx and oxid-admin-graphql. The rule that guarantees it: nothing outside src/GraphQL/ names a
 * class of those packages. Two checks hold it (AdminApiIsOptionalTest adds the container wiring of the admin API):
 * a static scan of every other file, and a child process that declares every other class where none of the
 * packages exist.
 */
final class GraphQLIsOptionalTest extends TestCase
{
    private const FORBIDDEN_NAMESPACES = ['OxidEsales\\GraphQL\\', 'TheCodingMachine\\GraphQLite\\', 'GraphQL\\'];

    public function testNoFileOutsideTheGraphQLDirectoryNamesAClassOfAGraphQLPackage(): void
    {
        $offences = [];
        foreach ($this->nonGraphQLSourceFiles() as $path) {
            foreach ($this->referencedNames((string) file_get_contents($path)) as $name) {
                $offences[] = substr($path, strlen($this->sourceRoot()) + 1) . ' -> ' . $name;
            }
        }

        self::assertSame([], $offences, 'only src/GraphQL/ may use, extend, implement or type-hint a GraphQL package class');
    }

    public function testTheScanSeesAReferenceInUseExtendsAndImplements(): void
    {
        $source = '<?php namespace A; use OxidEsales\GraphQL\Base\Exception\Error; use GraphQL\Type\Definition\Type as T;'
            . ' final class B extends Error implements \TheCodingMachine\GraphQLite\Annotations\Query {} // OxidEsales\GraphQL\Comment';

        self::assertSame(
            ['OxidEsales\GraphQL\Base\Exception\Error', 'GraphQL\Type\Definition\Type', 'TheCodingMachine\GraphQLite\Annotations\Query'],
            $this->referencedNames($source),
            'comments are ignored, every real reference is found'
        );
    }

    public function testEveryNonGraphQLClassIsDeclaredWhereNoGraphQLPackageExists(): void
    {
        $script = __DIR__ . '/../Support/load-non-graphql-classes.php';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertMatchesRegularExpression('/^OK (\d+)$/m', $output, $output);
        preg_match('/^OK (\d+)$/m', $output, $matches);
        self::assertGreaterThanOrEqual(150, (int) $matches[1], 'the classes were actually declared');
    }

    private function sourceRoot(): string
    {
        return (string) realpath(__DIR__ . '/../../../src');
    }

    /**
     * @return list<string>
     */
    private function nonGraphQLSourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourceRoot(), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() === 'php' && !str_contains($path, '/src/GraphQL/')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return list<string> the names in code (not in comments or strings) that start in a GraphQL package
     */
    private function referencedNames(string $source): array
    {
        $names = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            foreach (self::FORBIDDEN_NAMESPACES as $namespace) {
                if (str_starts_with($name, $namespace)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
