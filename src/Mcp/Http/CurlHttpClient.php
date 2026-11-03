<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Http;

/**
 * https only, no redirects (a JWKS URI is configured, never followed
 * elsewhere), short timeouts and a size cap: the document is small and the
 * caller waits for it inside a request.
 */
final class CurlHttpClient implements HttpClientInterface
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_BODY_BYTES = 262144;
    private const HTTP_OK = 200;

    public function get(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new HttpRequestFailedException('Only https URLs are fetched');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpRequestFailedException('curl could not be initialised');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status !== self::HTTP_OK) {
            throw new HttpRequestFailedException(sprintf('Request failed with status %d', $status));
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpRequestFailedException('Response is too large');
        }

        return $body;
    }
}
