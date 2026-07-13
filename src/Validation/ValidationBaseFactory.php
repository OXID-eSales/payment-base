<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContextInterface;

/**
 * Factory for creating ValidationBase instances bound to specific modules.
 *
 * Each payment provider (Stripe, Mollie, PayPal) needs its own ValidationBase
 * instance with the correct module ID. This factory creates those instances on
 * demand, ensuring that:
 *
 * 1. ValidationBaseInterface is defined ONCE in payment-base, not duplicated
 *    in each provider module
 * 2. Each provider gets an isolated instance with their specific module ID
 * 3. No conflicts when multiple providers are active in the same shop
 *
 * Usage in provider services.yaml:
 * ```yaml
 * OxidEsales\Payments\Provider\Service\UserDataValidatorInterface:
 *   class: OxidEsales\Payments\Provider\Service\UserDataValidator
 *   arguments:
 *     - !phpfn OxidEsales\PaymentBase\Validation\ValidationBaseFactory:create
 *         - 'oe_payments_provider'
 * ```
 *
 * Or via the factory service directly in services that need it:
 * ```yaml
 * OxidEsales\Payments\Provider\Service\UserDataValidator:
 *   factory: ['@OxidEsales\PaymentBase\Validation\ValidationBaseFactory', 'create']
 *   arguments:
 *     - 'oe_payments_provider'
 * ```
 */
class ValidationBaseFactory
{
    public function __construct(
        private readonly ValidationRuleLoaderInterface $loader,
        private readonly ?ModuleConfigurationDaoInterface $moduleConfigDao = null,
        private readonly ?BasicContextInterface $context = null,
    ) {
    }

    /**
     * Create a ValidationBase instance for the given module ID.
     *
     * @param string $moduleId The module ID (e.g., 'oe_payments_stripe_wallet')
     */
    public function create(string $moduleId): ValidationBaseInterface
    {
        return new ValidationBase($moduleId, $this->loader);
    }
}