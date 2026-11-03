<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

/**
 * The bearer token is not acceptable. The message names the failed check,
 * never a token or key value, so it may be logged.
 */
final class InvalidAgentTokenException extends \RuntimeException
{
}
