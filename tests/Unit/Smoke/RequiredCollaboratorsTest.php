<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Smoke;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * VR-25 D3: a collaborator is required or it is not there. A constructor parameter that defaults to null / new X(),
 * or a `?? new X()` fallback, silently picks an implementation the container never chose.
 *
 * Only value-object / seam parameters are allowed to stay optional, and each one is listed here with its reason.
 */
final class RequiredCollaboratorsTest extends TestCase
{
    /**
     * Optional parameters of these types are values, not collaborators: a missing value is meaningful.
     *
     * @var list<string>
     */
    private const VALUE_TYPES = [
        '', 'string', 'int', 'float', 'bool', 'array', 'mixed', 'iterable',
        'DateTimeInterface', 'DateTimeImmutable', 'DateTime', 'Throwable', 'Money', 'Address',
        'BillingAddress', 'ShippingAddress', 'GuestDetails', 'GuestAddress',
    ];

    /**
     * "Class::$param" => why this optional is not a collaborator.
     *
     * @var array<string, string>
     */
    private const ALLOWED_OPTIONALS = [
        'GraphQL\\Service\\BearerTokenIdReader::$token' => 'absent token is the anonymous caller (a value, not a collaborator)',
        'GraphQL\\Admin\\Service\\PaymentContractDetails::$declaredActionOptions' => 'value: nothing declared by the provider',
        'GraphQL\\Admin\\Service\\PaymentContractDetails::$declaredBounds' => 'value: nothing declared by the provider',
        'GraphQL\\Admin\\Service\\OperationIdempotency::$now' => 'clock seam for tests; the real clock is the only production value',
        'Webhook\\Guard\\RateLimitGuard::$clock' => 'clock seam for tests; the real clock is the only production value',
        'Service\\Factory\\AbstractFileLoggerFactory::$isEnabled' => 'enable switch; null means always on',
        'Checkout\\Return\\ReturnAccessRequest::$legacyTokens' => 'request value object',
        'EventSystem\\Handler\\EarlyOrderCreationHandler::$eventLogger' => 'optional diagnostic file log only Stripe binds; nothing to default to',
        'EventSystem\\Handler\\PaymentAuthorizedEventHandler::$eventLogger' => 'optional diagnostic file log only Stripe binds; nothing to default to',
        'EventSystem\\Handler\\OrderPaymentCompletedHandler::$eventLogger' => 'optional diagnostic file log only Stripe binds; nothing to default to',
        'Admin\\PaymentAdminController::$panelRegistry' => 'OXID admin controllers get no constructor DI: container lookup seam, throws when absent',
        'Admin\\PaymentAdminController::$contractRepository' => 'OXID admin controllers get no constructor DI: container lookup seam, throws when absent',
        'Admin\\PaymentAdminController::$actionDispatcher' => 'OXID admin controllers get no constructor DI: container lookup seam, throws when absent',
        'Admin\\PaymentAdminController::$moneyActions' => 'OXID admin controllers get no constructor DI: container lookup seam, throws when absent',
    ];

    public function testNoProductionConstructorDefaultsACollaborator(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            foreach ($this->optionalConstructorParams($code) as $param) {
                $key = $this->classKey($relative) . '::$' . $param;
                if (!isset(self::ALLOWED_OPTIONALS[$key])) {
                    $offenders[] = $key;
                }
            }
        }

        self::assertSame([], $offenders, "Make these constructor parameters required (and wire them):\n"
            . implode("\n", $offenders));
    }

    public function testNoProductionCodeFallsBackToANewCollaborator(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            if (preg_match('/\?\?\s*new\s+(?!DateTime)[A-Z\\\\]/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, "`?? new X()` fallbacks:\n" . implode("\n", $offenders));
    }

    public function testEveryAllowedOptionalStillExists(): void
    {
        $found = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            foreach ($this->optionalConstructorParams($code) as $param) {
                $found[] = $this->classKey($relative) . '::$' . $param;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::ALLOWED_OPTIONALS), $found)),
            'Stale allow-list entries: remove them.'
        );
    }

    /**
     * @return iterable<string, string> path relative to src/ => code
     */
    private function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
        }
    }

    private function classKey(string $relative): string
    {
        return substr($relative, 0, -4) === '' ? $relative : str_replace('/', '\\', substr($relative, 0, -4));
    }

    /**
     * @return list<string> names of constructor parameters defaulting to null or `new X()`
     */
    private function optionalConstructorParams(string $code): array
    {
        $start = strpos($code, 'function __construct(');
        if ($start === false) {
            return [];
        }
        $params = $this->balancedParameterList($code, $start + strlen('function __construct('));
        $names = [];
        foreach ($this->splitTopLevel($params) as $param) {
            if (preg_match('/(?:(\??[\w\\\\]+)\s+)?\$(\w+)\s*=\s*(null\b|new\s)/', $param, $match) !== 1) {
                continue;
            }
            if (!in_array(ltrim($match[1], '?\\'), self::VALUE_TYPES, true)) {
                $names[] = $match[2];
            }
        }

        return $names;
    }

    private function balancedParameterList(string $code, int $from): string
    {
        $depth = 1;
        for ($i = $from, $length = strlen($code); $i < $length; $i++) {
            $depth += match ($code[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return substr($code, $from, $i - $from);
            }
        }

        return substr($code, $from);
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($list) as $char) {
            $depth += match ($char) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }
}
