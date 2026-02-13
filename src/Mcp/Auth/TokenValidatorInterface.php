<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

interface TokenValidatorInterface
{
    /**
     * Validate an access token and extract claims.
     *
     * @param string $token Raw access token (JWT or opaque)
     */
    public function validate(string $token): TokenValidationResult;
}
