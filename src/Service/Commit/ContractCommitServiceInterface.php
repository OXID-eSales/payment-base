<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Commit;

/**
 * Commit a contract because the provider says it is paid.
 *
 * Sprint 15 / S4 (GRAPH-QL). Until 2026-10-06 the only path from PENDING to
 * COMMITTED was the browser return leg ({@see \OxidEsales\PaymentBase\Controller\CheckoutReturnResponder});
 * webhooks only fulfilled contracts that were committed already. A headless
 * client that never comes back (closed tab, killed app) therefore left a
 * paid PSP session on a PENDING contract that the stale cleanup later
 * cancelled - money taken, no order. Provider webhook handlers and headless
 * return mutations call this instead: it is idempotent, refuses what must
 * not be committed, and runs the same handler chain the return leg runs.
 *
 * @since 3.0.0
 */
interface ContractCommitServiceInterface
{
    public function commit(PaymentConfirmation $confirmation): CommitOutcome;
}
