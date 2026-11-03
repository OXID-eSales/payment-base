<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Logging;

use Throwable;

/**
 * What a log line may say about a Throwable (VR-18 L1): its class, its code and what the
 * registered extractors know to be safe. The message is never part of it - the message of a DBAL
 * or SDK exception embeds bound parameters or the request body, i.e. customer data and tokens.
 */
final class ThrowableSummary implements ThrowableSummaryInterface
{
    /**
     * @param iterable<ThrowableSummaryExtractorInterface> $extractors
     */
    public function __construct(private readonly iterable $extractors)
    {
    }

    /**
     * @inheritDoc
     */
    public function fromThrowable(Throwable $throwable): array
    {
        $summary = [];
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($throwable)) {
                $summary = array_merge($summary, $extractor->summarize($throwable));
            }
        }

        // Ours last: an extractor cannot rename the class or hide the code.
        $summary = array_merge($summary, ['class' => $throwable::class, 'code' => $throwable->getCode()]);

        $sqlState = $this->sqlStateOf($throwable);
        if ($sqlState !== null) {
            $summary['sqlState'] = $sqlState;
        }

        return $summary;
    }

    /**
     * @inheritDoc
     */
    public function toLine(Throwable $throwable): string
    {
        $summary = $this->fromThrowable($throwable);
        $line = $summary['class'] . ' (code ' . $summary['code'] . ')';

        return isset($summary['sqlState']) ? $line . ' SQLSTATE ' . $summary['sqlState'] : $line;
    }

    /**
     * The state of the database error itself or of the one it wraps: a repository turns a DBAL
     * error into its own exception, and the state is still what says what went wrong.
     */
    private function sqlStateOf(Throwable $throwable): ?string
    {
        for ($cause = $throwable; $cause !== null; $cause = $cause->getPrevious()) {
            if (!method_exists($cause, 'getSQLState')) {
                continue;
            }

            $sqlState = $cause->getSQLState();
            if (is_string($sqlState) && $sqlState !== '') {
                return $sqlState;
            }
        }

        return null;
    }
}
