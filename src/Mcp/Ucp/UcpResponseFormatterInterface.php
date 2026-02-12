<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

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
