<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\ReturnUrl;

use InvalidArgumentException;

/**
 * A client-supplied return URL the policy will not send a shopper to.
 *
 * The message goes into the GraphQL error and therefore into the shop log
 * (graphql-base logs every error message), so it names the origin of the URL
 * and nothing else: path, query (`session_id`, `uid`, ...), fragment and
 * credentials stay out. The full URL is available through fullUrl() for debug
 * logging only.
 *
 * @since 3.0.0
 */
final class ReturnUrlRejectedException extends InvalidArgumentException
{
    public const NOT_ABSOLUTE = 'not_absolute';

    public const SCHEME_NOT_ALLOWED = 'scheme_not_allowed';

    public const CREDENTIALS_NOT_ALLOWED = 'credentials_not_allowed';

    public const ORIGIN_NOT_ALLOWED = 'origin_not_allowed';

    private const NO_ORIGIN = '(no origin)';

    public function __construct(
        private readonly string $url,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('Return URL rejected (%s): %s', $reason, self::describeOrigin($url)));
    }

    /**
     * The URL as the client sent it. For debug-level logging only: it may
     * carry tokens in its query.
     */
    public function fullUrl(): string
    {
        return $this->url;
    }

    private static function describeOrigin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        if ($scheme === '' || preg_match('/^[a-z][a-z0-9+.-]*$/i', $scheme) !== 1) {
            return self::NO_ORIGIN;
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            return $scheme . ':';
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
