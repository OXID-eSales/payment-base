<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

/**
 * STRP-176 — reads the local Mailpit (docker service `mailpit`), which msmtp
 * delivers the shop's mail to. The inbox is shared with other suites: only
 * ever query one test's own address, never clear it.
 */
final class MailpitClient
{
    public function __construct(private readonly string $apiBase = 'http://mailpit:8025/api/v1')
    {
    }

    public function isReachable(): bool
    {
        return $this->get('/info') !== null;
    }

    public function countTo(string $address): int
    {
        $result = $this->get('/search?' . http_build_query(['query' => 'to:' . $address]));

        return is_array($result) ? (int) ($result['messages_count'] ?? 0) : 0;
    }

    /**
     * Mail leaves PHP synchronously but Mailpit indexes it a moment later.
     */
    public function waitForCountTo(string $address, int $expected, int $timeoutMs = 10000): int
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        do {
            $count = $this->countTo($address);
            if ($count >= $expected) {
                return $count;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        return $count;
    }

    /**
     * @return list<array{id: string, subject: string}> newest first
     */
    public function messagesTo(string $address): array
    {
        $result = $this->get('/search?' . http_build_query(['query' => 'to:' . $address]));
        $messages = is_array($result) && is_array($result['messages'] ?? null) ? $result['messages'] : [];

        return array_values(array_map(
            static fn (mixed $m): array => [
                'id' => is_array($m) ? (string) ($m['ID'] ?? '') : '',
                'subject' => is_array($m) ? (string) ($m['Subject'] ?? '') : '',
            ],
            $messages
        ));
    }

    public function textOf(string $messageId): string
    {
        $message = $this->get('/message/' . rawurlencode($messageId));

        return is_array($message) ? (string) ($message['Text'] ?? '') . (string) ($message['HTML'] ?? '') : '';
    }

    /**
     * @return array<mixed>|null
     */
    private function get(string $path): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents($this->apiBase . $path, false, $context);
        if ($body === false) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
