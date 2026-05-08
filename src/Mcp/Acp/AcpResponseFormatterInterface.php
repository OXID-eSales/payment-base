<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Acp;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

interface AcpResponseFormatterInterface
{
    /**
     * Format a contract as an ACP checkout response.
     *
     * @return array<string, mixed> ACP-compliant checkout JSON
     */
    public function formatCheckout(PaymentContractInterface $contract): array;

    /**
     * Format a completed checkout as an ACP order response.
     *
     * @return array<string, mixed> ACP order JSON with id, checkout_session_id, permalink_url
     */
    public function formatOrder(PaymentContractInterface $contract, string $orderPermalink): array;

    /**
     * Format a not-found error.
     *
     * @return array<string, mixed> ACP error response
     */
    public function notFoundError(string $checkoutId): array;

    /**
     * Format a validation error.
     *
     * @return array<string, mixed> ACP error response
     */
    public function validationError(string $message, ?string $param = null): array;
}
