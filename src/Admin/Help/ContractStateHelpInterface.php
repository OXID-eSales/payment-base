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
    /**
     * One row per contract state a checkout can reach, in ladder order.
     *
     * @return list<ContractStateHelpRow>
     */
    public function rows(): array;
}
