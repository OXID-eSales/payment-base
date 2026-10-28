<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * What came of handing an admin action to the provider module: one of the {@see ProviderActionRunnerInterface}
 * statuses and, for a refusal, the provider's code and sentence.
 *
 * @since 1.7.0
 */
final readonly class ProviderActionOutcome
{
    private function __construct(
        public string $status,
        public ?string $refusalCode = null,
        public ?string $refusalMessage = null,
    ) {
    }

    public static function ran(): self
    {
        return new self(ProviderActionRunnerInterface::RAN);
    }

    public static function unavailable(): self
    {
        return new self(ProviderActionRunnerInterface::UNAVAILABLE);
    }

    public static function failed(): self
    {
        return new self(ProviderActionRunnerInterface::FAILED);
    }

    public static function refused(string $code, string $message): self
    {
        return new self(ProviderActionRunnerInterface::REFUSED, $code, $message);
    }
}
