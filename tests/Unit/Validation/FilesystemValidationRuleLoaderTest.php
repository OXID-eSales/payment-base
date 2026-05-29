<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Validation\FilesystemValidationRuleLoader;
use OxidEsales\PaymentBase\Validation\PluginPathResolverInterface;
use OxidEsales\PaymentBase\Validation\RuleSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemValidationRuleLoader::class)]
class FilesystemValidationRuleLoaderTest extends TestCase
{
    private string $tmpDir;
    private PluginPathResolverInterface&MockObject $pathResolver;
    private FilesystemValidationRuleLoader $loader;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pb_validation_test_' . uniqid('', true);
        mkdir($this->tmpDir . '/src/Resources', 0777, true);

        $this->pathResolver = $this->createMock(PluginPathResolverInterface::class);
        $this->loader = new FilesystemValidationRuleLoader($this->pathResolver);
    }

    protected function tearDown(): void
    {
        $rulesFile = $this->tmpDir . '/src/Resources/validation-rules.php';
        if (file_exists($rulesFile)) {
            unlink($rulesFile);
        }
        rmdir($this->tmpDir . '/src/Resources');
        rmdir($this->tmpDir . '/src');
        rmdir($this->tmpDir);
    }

    // RED test 8
    public function testLoadsRulesForKnownPlugin(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Resources/validation-rules.php',
            '<?php return ["fields" => [["field" => "firstName", "rules" => ["allow" => "LETTERS"]]]];'
        );
        $this->pathResolver->method('resolvePath')->willReturn($this->tmpDir);

        $result = $this->loader->loadFor('test_plugin');

        $this->assertArrayHasKey('firstName', $result);
        $this->assertInstanceOf(RuleSet::class, $result['firstName']);
    }

    // RED test 9
    public function testThrowsForMissingFile(): void
    {
        $this->pathResolver->method('resolvePath')->willReturn($this->tmpDir . '/nonexistent');

        $this->expectException(InvalidArgumentException::class);

        $this->loader->loadFor('missing_plugin');
    }

    public function testThrowsForMalformedShape(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Resources/validation-rules.php',
            '<?php return ["not_fields" => []];'
        );
        $this->pathResolver->method('resolvePath')->willReturn($this->tmpDir);

        $this->expectException(InvalidArgumentException::class);

        $this->loader->loadFor('test_plugin');
    }

    public function testMultipleFieldsReturnCorrectMap(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Resources/validation-rules.php',
            '<?php return ["fields" => [
                ["field" => "firstName", "rules" => ["allow" => "LETTERS"]],
                ["field" => "lastName", "rules" => ["allow" => "LETTERS", "block" => ":"]]
            ]];'
        );
        $this->pathResolver->method('resolvePath')->willReturn($this->tmpDir);

        $result = $this->loader->loadFor('test_plugin');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('lastName', $result);
    }
}
