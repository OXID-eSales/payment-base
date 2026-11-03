<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

/**
 * RFC 7515 base64url without padding.
 */
final class Base64Url
{
    public static function decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
