<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\FileLogger
 * @group sprint-14
 * @group logging
 */
final class FileLoggerTest extends TestCase
{
    private string $testLogDir;
    private string $testLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testLogDir = sys_get_temp_dir() . '/stripe_test_logs_' . uniqid();
        $this->testLogFile = $this->testLogDir . '/test.log';
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
        if (is_dir($this->testLogDir)) {
            rmdir($this->testLogDir);
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $logger = new FileLogger($this->testLogFile);

        $this->assertInstanceOf(FileLoggerInterface::class, $logger);
    }

    /**
     * @test
     */
    public function logsToFile(): void
    {
        $logger = new FileLogger($this->testLogFile);

        $logger->log('Test message');

        $this->assertFileExists($this->testLogFile);
        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Test message', $content);
    }

    /**
     * @test
     */
    public function createsDirectoryIfNotExists(): void
    {
        $this->assertDirectoryDoesNotExist($this->testLogDir);

        $logger = new FileLogger($this->testLogFile);
        $logger->log('Test message');

        $this->assertDirectoryExists($this->testLogDir);
        $this->assertFileExists($this->testLogFile);
    }

    /**
     * @test
     */
    public function formatsLogEntryWithTimestamp(): void
    {
        $logger = new FileLogger($this->testLogFile);

        $logger->log('Test message');

        $content = file_get_contents($this->testLogFile);
        // Format: [YYYY-MM-DD HH:MM:SS] Message
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] Test message/',
            $content
        );
    }

    /**
     * @test
     */
    public function formatsLogEntryWithPrefix(): void
    {
        $logger = new FileLogger($this->testLogFile, 'RECONCILE');

        $logger->log('SUCCESS: Test');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('RECONCILE SUCCESS: Test', $content);
    }

    /**
     * @test
     */
    public function appendsToExistingFile(): void
    {
        mkdir($this->testLogDir, 0755, true);
        file_put_contents($this->testLogFile, "Existing content\n");

        $logger = new FileLogger($this->testLogFile);
        $logger->log('New message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Existing content', $content);
        $this->assertStringContainsString('New message', $content);
    }

    /**
     * @test
     */
    public function formatsContextAsJson(): void
    {
        $logger = new FileLogger($this->testLogFile);

        $logger->log('Test message', ['order_id' => '123', 'status' => 'success']);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('{"order_id":"123","status":"success"}', $content);
    }

    /**
     * @test
     */
    public function emptyContextIsNotAppended(): void
    {
        $logger = new FileLogger($this->testLogFile);

        $logger->log('Test message', []);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('{}', $content);
        $this->assertStringContainsString("Test message\n", $content);
    }

    /**
     * @test
     */
    public function eachLogEntryEndsWithNewline(): void
    {
        $logger = new FileLogger($this->testLogFile);

        $logger->log('First message');
        $logger->log('Second message');

        $content = file_get_contents($this->testLogFile);
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines);
    }
}
