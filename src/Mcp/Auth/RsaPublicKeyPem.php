<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

/**
 * A JWK's RSA modulus and exponent as a PEM SubjectPublicKeyInfo, which is
 * what openssl_verify() takes. Minimal DER writer: only what that needs.
 */
final class RsaPublicKeyPem
{
    private const TAG_INTEGER = "\x02";
    private const TAG_BIT_STRING = "\x03";
    private const TAG_NULL = "\x05\x00";
    private const TAG_SEQUENCE = "\x30";
    /** OID 1.2.840.113549.1.1.1 (rsaEncryption) */
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
    private const LONG_FORM_FLAG = 0x80;
    private const SHORT_FORM_MAX_LENGTH = 127;

    public static function fromComponents(string $modulus, string $exponent): string
    {
        $rsaPublicKey = self::der(self::TAG_SEQUENCE, self::integer($modulus) . self::integer($exponent));
        $algorithm = self::der(self::TAG_SEQUENCE, self::OID_RSA_ENCRYPTION . self::TAG_NULL);
        $spki = self::der(self::TAG_SEQUENCE, $algorithm . self::der(self::TAG_BIT_STRING, "\x00" . $rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $unsignedBigEndian): string
    {
        $trimmed = ltrim($unsignedBigEndian, "\x00");
        if ($trimmed === '' || ord($trimmed[0]) >= self::LONG_FORM_FLAG) {
            $trimmed = "\x00" . $trimmed;
        }

        return self::der(self::TAG_INTEGER, $trimmed);
    }

    private static function der(string $tag, string $content): string
    {
        return $tag . self::length(strlen($content)) . $content;
    }

    private static function length(int $length): string
    {
        if ($length <= self::SHORT_FORM_MAX_LENGTH) {
            return pack('C', $length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return pack('C', self::LONG_FORM_FLAG | strlen($bytes)) . $bytes;
    }
}
