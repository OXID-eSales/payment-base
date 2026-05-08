<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Ucp;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

interface UcpResponseFormatterInterface
{
    /**
     * @return array<string, mixed>
     */
    public function formatCheckoutSession(PaymentContractInterface $contract): array;

    /**
     * @return array<string, mixed>
     */
    public function formatError(string $type, string $message, ?string $param = null): array;
}
