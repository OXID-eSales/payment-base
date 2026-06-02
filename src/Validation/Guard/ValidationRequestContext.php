<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Immutable value object carrying a parsed validation request.
 *
 * Instantiate directly in unit tests; use the static factory
 * `fromRequest()` in production (reads PHP globals).
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class ValidationRequestContext
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        private readonly string $method,
        private readonly int $bodySize,
        private readonly array $fields,
        private readonly string $pluginModuleId,
        private readonly ?string $csrfToken,
        private readonly ?string $sessionId,
        private readonly ?string $originHeader,
        private readonly ?string $refererHeader,
    ) {
    }

    /**
     * Static factory — reads PHP globals for production use.
     *
     * @param array<string, mixed>|null $serverArray overrides $_SERVER (tests)
     * @param array<string, mixed>|null $bodyArray   overrides $_POST (tests)
     */
    public static function fromRequest(
        ?array $serverArray = null,
        ?array $bodyArray = null,
    ): self {
        /** @var array<string, mixed> $server */
        $server = $serverArray ?? $_SERVER;
        /** @var array<string, mixed> $body */
        $body = $bodyArray ?? $_POST;

        $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            ? strtoupper($server['REQUEST_METHOD'])
            : 'GET';

        $rawLength = $server['CONTENT_LENGTH'] ?? 0;
        $bodySize = is_numeric($rawLength) ? (int) $rawLength : 0;

        $pluginModuleId = isset($body['pluginModuleId']) && is_string($body['pluginModuleId'])
            ? $body['pluginModuleId']
            : '';

        $csrfToken = isset($body['stoken']) && is_string($body['stoken'])
            ? $body['stoken']
            : null;

        $sessionId = isset($server['HTTP_COOKIE']) ? self::extractSessionId($server) : null;

        $originHeader = isset($server['HTTP_ORIGIN']) && is_string($server['HTTP_ORIGIN'])
            ? $server['HTTP_ORIGIN']
            : null;

        $refererHeader = isset($server['HTTP_REFERER']) && is_string($server['HTTP_REFERER'])
            ? $server['HTTP_REFERER']
            : null;

        $fields = self::extractFields($body);

        return new self(
            method: $method,
            bodySize: $bodySize,
            fields: $fields,
            pluginModuleId: $pluginModuleId,
            csrfToken: $csrfToken,
            sessionId: $sessionId,
            originHeader: $originHeader,
            refererHeader: $refererHeader,
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getBodySize(): int
    {
        return $this->bodySize;
    }

    /** @return array<string, mixed> */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getPluginModuleId(): string
    {
        return $this->pluginModuleId;
    }

    public function getCsrfToken(): ?string
    {
        return $this->csrfToken;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getOriginHeader(): ?string
    {
        return $this->originHeader;
    }

    public function getRefererHeader(): ?string
    {
        return $this->refererHeader;
    }

    public function getFieldCount(): int
    {
        return count($this->fields);
    }

    /**
     * Extract field-map, stripping the meta fields (pluginModuleId, stoken).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function extractFields(array $body): array
    {
        $metaKeys = ['pluginModuleId', 'stoken'];
        $fields = [];

        foreach ($body as $key => $value) {
            if (!in_array($key, $metaKeys, true)) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Attempt to extract PHP session id from the cookie header.
     * Returns null when no session cookie is present.
     *
     * @param array<string, mixed> $server
     */
    private static function extractSessionId(array $server): ?string
    {
        $sessionId = session_id();
        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        return null;
    }
}
