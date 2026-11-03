<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

interface ClockInterface
{
    /**
     * Unix timestamp.
     */
    public function now(): int;
}
