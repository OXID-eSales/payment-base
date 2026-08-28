<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Read accessor for the "Cleanup period" module setting.
 *
 * Kept behind an interface so the console command depends on the question
 * ("how old is old enough?") rather than on OXID's setting store.
 */
interface NotFinishedOrderCleanupSettingsInterface
{
    /**
     * Age in days beyond which an unfinished order is considered abandoned.
     *
     * Always >= 1: a period of zero would select the checkout that is in
     * progress right now.
     */
    public function getCleanupPeriodDays(): int;
}
