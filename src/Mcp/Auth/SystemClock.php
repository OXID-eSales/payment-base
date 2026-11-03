<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
