<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettings;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The read accessor for the "Cleanup period" module setting
 * (iPaymentBaseCleanupPeriod), expressed in days.
 *
 * The container lookup sits behind the protected readRawPeriod() seam, which
 * hands back whatever the shop stored — verbatim. All interpretation lives in
 * getCleanupPeriodDays() so it is testable without the shop bootstrap, and so
 * a surprising stored shape shows up here rather than at runtime.
 */
final class NotFinishedOrderCleanupSettingsTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            NotFinishedOrderCleanupSettingsInterface::class,
            new NotFinishedOrderCleanupSettings()
        );
    }

    /**
     * OXID stores a 'num' module setting as a string, and hands it back as one.
     * Reading it as an int is what the shop's own typed facade gets wrong, so
     * the coercion has to happen here.
     */
    #[DataProvider('storedValues')]
    public function testCoercesWhateverTheShopStored(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, $this->settingsReturning($stored)->getCleanupPeriodDays());
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function storedValues(): array
    {
        $default = NotFinishedOrderCleanupSettings::DEFAULT_PERIOD_DAYS;

        return [
            'string, as OXID actually stores it' => ['14', 14],
            'genuine int' => [21, 21],
            'numeric string with whitespace' => [" 30 ", 30],
            // A period of zero would select every unfinished order in the shop,
            // including the one being paid for right now. An unset setting
            // reads as an empty string, so the floor is what keeps a fresh
            // install safe.
            'unset / empty' => ['', $default],
            'zero' => ['0', $default],
            'negative' => ['-5', $default],
            'not a number' => ['soon', $default],
            'null' => [null, $default],
        ];
    }

    /**
     * Reading module settings needs the shop container. When it is not there
     * (CLI before bootstrap, broken DI) the caller must still get a usable,
     * conservative number rather than an exception.
     */
    public function testFallsBackToTheDefaultWhenTheContainerIsUnavailable(): void
    {
        $settings = new class () extends NotFinishedOrderCleanupSettings {
            protected function readRawPeriod(): mixed
            {
                throw new RuntimeException('no container');
            }
        };

        $this->assertSame(
            NotFinishedOrderCleanupSettings::DEFAULT_PERIOD_DAYS,
            $settings->getCleanupPeriodDays()
        );
    }

    private function settingsReturning(mixed $value): NotFinishedOrderCleanupSettings
    {
        return new class ($value) extends NotFinishedOrderCleanupSettings {
            public function __construct(private readonly mixed $value)
            {
            }

            protected function readRawPeriod(): mixed
            {
                return $this->value;
            }
        };
    }
}
