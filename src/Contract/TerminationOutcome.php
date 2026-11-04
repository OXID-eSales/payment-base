<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

/**
 * What {@see ContractTerminationServiceInterface} did with a contract.
 */
enum TerminationOutcome
{
    /** The contract was ended and its linked order mirrored. */
    case Terminated;

    /** The contract was already in a terminal state: nothing to do. */
    case AlreadyTerminal;

    /**
     * An expiry reached a committed contract: its payment was taken, so the state machine refuses it.
     * The provider decides what that means (ignore it, flag the order).
     */
    case Committed;
}
