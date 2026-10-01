<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Twig;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpInterface;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpRow;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig function `oe_payment_contract_state_help()` (Sprint 14, MOL-10): hands the shared contract-state
 * Help rows to any admin template - payment-base's own Settings tab, the providers' Settings tabs and
 * their order panels - so there is exactly one table in PHP and none in Twig.
 */
class ContractStateHelpExtension extends AbstractExtension
{
    public function __construct(private readonly ContractStateHelpInterface $help)
    {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('oe_payment_contract_state_help', [$this, 'rows']),
        ];
    }

    /**
     * @return list<ContractStateHelpRow>
     */
    public function rows(): array
    {
        return $this->help->rows();
    }
}
