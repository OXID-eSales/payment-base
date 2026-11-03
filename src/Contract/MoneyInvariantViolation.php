<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DomainException;

/**
 * A booking would break a money invariant of the contract
 * (captured above the authorized amount, refunded above the captured amount).
 *
 * Extends DomainException so existing callers that already handle a refused
 * booking keep working.
 */
final class MoneyInvariantViolation extends DomainException
{
}
