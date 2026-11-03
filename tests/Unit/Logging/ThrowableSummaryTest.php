<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Logging;

use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryExtractorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ThrowableSummary::class)]
final class ThrowableSummaryTest extends TestCase
{
    public function testADatabaseErrorKeepsTheSqlStateButNoBoundParameter(): void
    {
        $dbalError = new class (
            "An exception occurred while executing 'UPDATE t SET mails = ?' with params"
            . ' ["<p>Dear Zoë, zoe@example.test, Hauptstr. 1</p>"]: SQLSTATE[42S22]: Column not found',
            1054
        ) extends DbalException {
            public function getSQLState(): string
            {
                return '42S22';
            }
        };

        $summary = (new ThrowableSummary([]))->fromThrowable($dbalError);

        self::assertSame('42S22', $summary['sqlState']);
        self::assertSame(1054, $summary['code']);
        self::assertStringNotContainsString('zoe@example.test', (string) json_encode($summary));
        self::assertStringNotContainsString('Hauptstr', (string) json_encode($summary));
    }

    public function testTheSqlStateOfAWrappedDatabaseErrorIsFound(): void
    {
        $dbalError = new class ('params ["zoe@example.test"]') extends DbalException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };

        $summary = (new ThrowableSummary([]))->fromThrowable(new RuntimeException('Failed to store the mails', 0, $dbalError));

        self::assertSame(RuntimeException::class, $summary['class']);
        self::assertSame('23000', $summary['sqlState']);
    }

    public function testAnUnknownThrowableYieldsItsClassAndCodeOnly(): void
    {
        $summary = (new ThrowableSummary([]))->fromThrowable(new RuntimeException('secret zoe@example.test', 7));

        self::assertSame(['class' => RuntimeException::class, 'code' => 7], $summary);
    }

    public function testAProviderExtractorAddsItsFieldsForTheExceptionsItSupports(): void
    {
        $summary = (new ThrowableSummary([$this->extractorFor(InvalidArgumentException::class, ['httpStatus' => 422])]))
            ->fromThrowable(new InvalidArgumentException('Request body: {"cardToken":"tkn_x"}'));

        self::assertSame(422, $summary['httpStatus']);
        self::assertStringNotContainsString('tkn_', (string) json_encode($summary));
    }

    public function testAnExtractorForAnotherExceptionIsNotAsked(): void
    {
        $summary = (new ThrowableSummary([$this->extractorFor(InvalidArgumentException::class, ['httpStatus' => 422])]))
            ->fromThrowable(new RuntimeException('x'));

        self::assertArrayNotHasKey('httpStatus', $summary);
    }

    public function testAnExtractorCannotOverwriteTheClass(): void
    {
        $summary = (new ThrowableSummary([$this->extractorFor(RuntimeException::class, ['class' => 'Forged'])]))
            ->fromThrowable(new RuntimeException('x'));

        self::assertSame(RuntimeException::class, $summary['class']);
    }

    public function testTheLineNamesClassCodeAndSqlStateWithoutTheMessage(): void
    {
        $line = (new ThrowableSummary([]))->toLine(new RuntimeException('secret zoe@example.test', 7));

        self::assertSame(RuntimeException::class . ' (code 7)', $line);
    }

    /**
     * @param class-string<Throwable> $supported
     * @param array<string, scalar|null> $fields
     */
    private function extractorFor(string $supported, array $fields): ThrowableSummaryExtractorInterface
    {
        return new class ($supported, $fields) implements ThrowableSummaryExtractorInterface {
            /**
             * @param class-string<Throwable> $supported
             * @param array<string, scalar|null> $fields
             */
            public function __construct(private readonly string $supported, private readonly array $fields)
            {
            }

            public function supports(Throwable $throwable): bool
            {
                return $throwable instanceof $this->supported;
            }

            public function summarize(Throwable $throwable): array
            {
                return $this->fields;
            }
        };
    }
}
