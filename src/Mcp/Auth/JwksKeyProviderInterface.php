<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

interface JwksKeyProviderInterface
{
    /**
     * The issuer's RS256 signature key with this `kid`, as a PEM public key.
     *
     * @throws InvalidAgentTokenException when there is no such usable key or the keys cannot be had
     */
    public function publicKeyFor(string $kid): string;
}
