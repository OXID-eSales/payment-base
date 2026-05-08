<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Ucp;

interface UcpProfileInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @return array<UcpCapability>
     */
    public function getCapabilities(): array;
}
