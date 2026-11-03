<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * Is an address inside a CIDR range (or equal to a single address)? IPv4 and
 * IPv6; a family mismatch or anything unparsable is "no".
 */
final class IpRange
{
    public static function contains(string $cidr, string $ip): bool
    {
        $parts = explode('/', $cidr, 2);
        $subnetBin = self::binary($parts[0]);
        $ipBin = self::binary($ip);
        if ($subnetBin === null || $ipBin === null || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }
        $bits = $parts[1] ?? null;
        $maxBits = strlen($ipBin) * 8;
        $prefix = $bits === null ? $maxBits : (int) $bits;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if (strncmp($subnetBin, $ipBin, $fullBytes) !== 0) {
            return false;
        }
        $rest = $prefix % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($subnetBin[$fullBytes]) & $mask) === (ord($ipBin[$fullBytes]) & $mask);
    }

    private static function binary(string $address): ?string
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = inet_pton($address);

        return $binary === false ? null : $binary;
    }
}
