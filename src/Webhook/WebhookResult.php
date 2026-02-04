<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

/**
 * Value object representing the result of webhook processing.
 *
 * Immutable DTO returned by event handlers to indicate success/failure
 * and provide context about what action was taken.
 *
 * @since Sprint 13
 */
readonly class WebhookResult
{
    public function __construct(
        public bool $success,
        public string $action,
        public ?string $error = null
    ) {
    }

    /**
     * Create a success result.
     */
    public static function success(string $action): self
    {
        return new self(true, $action, null);
    }

    /**
     * Create a failure result.
     */
    public static function failure(string $action, string $error): self
    {
        return new self(false, $action, $error);
    }

    /**
     * Create a skipped result (success but no action taken).
     */
    public static function skipped(string $reason): self
    {
        return new self(true, 'skipped', $reason);
    }

    /**
     * Check if result indicates success.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if result indicates failure.
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{success: bool, action: string, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'action' => $this->action,
            'error' => $this->error,
        ];
    }
}
