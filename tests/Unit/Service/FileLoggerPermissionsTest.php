<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\FileLogger;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 70b: M3 — Restrictive file permissions.
 *
 * @covers \OxidEsales\PaymentComponent\Service\FileLogger
 * @group sprint-70b
 * @group security
 */
final class FileLoggerPermissionsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/stripe_logger_test_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $logFile = $this->tempDir . '/test.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    /** @test */
    public function logDirectoryCreatedWithRestrictivePermissions(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $logger = new FileLogger($logFile);

        $logger->log('test message');

        $this->assertDirectoryExists($this->tempDir);
        $perms = fileperms($this->tempDir) & 0777;
        $this->assertSame(0750, $perms, sprintf('Expected 0750, got %04o', $perms));
    }

    /** @test */
    public function logFileCreatedInsideRestrictiveDirectory(): void
    {
        $logFile = $this->tempDir . '/test.log';
        $logger = new FileLogger($logFile);

        $logger->log('test message');

        $this->assertFileExists($logFile);
        // File inherits directory-level protection: even if file is 0644,
        // the 0750 directory prevents 'other' users from accessing it.
        $dirPerms = fileperms($this->tempDir) & 0777;
        $this->assertSame(0, $dirPerms & 0007, 'Directory should block other-user access');
    }

    /** @test */
    public function existingDirectoryNotModified(): void
    {
        mkdir($this->tempDir, 0755, true);
        $originalPerms = fileperms($this->tempDir) & 0777;

        $logFile = $this->tempDir . '/test.log';
        $logger = new FileLogger($logFile);
        $logger->log('test message');

        $currentPerms = fileperms($this->tempDir) & 0777;
        $this->assertSame($originalPerms, $currentPerms, 'Existing directory permissions should not change');
    }
}
