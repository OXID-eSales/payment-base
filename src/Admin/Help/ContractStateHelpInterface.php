<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Help;

/**
 * The shared contract-state Help table (Sprint 14, MOL-10): rows a template can render, and the
 * idents the table and the "?" hint need besides the per-row meanings.
 */
interface ContractStateHelpInterface
{
    /** Idents the table and the hint need besides the per-row meanings (title, intro, headers, "none", "close"). */
    public const SHARED_IDENTS = [
        'PAYMENT_ADMIN_HELP',
        'PAYMENT_ADMIN_HELP_CONTRACT_STATES_INTRO',
        'PAYMENT_ADMIN_HELP_COL_CONTRACT_STATE',
        'PAYMENT_ADMIN_HELP_COL_MEANING',
        'PAYMENT_ADMIN_HELP_NONE',
        'PAYMENT_ADMIN_HELP_CLOSE',
    ];

    /**
     * One row per contract state a checkout can reach, in ladder order.
     *
     * @return list<ContractStateHelpRow>
     */
    public function rows(): array;
}
