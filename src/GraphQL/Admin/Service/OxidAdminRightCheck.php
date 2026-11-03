<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\Authorization\DataType\AdminIdentity;
use OxidEsales\GraphQL\Admin\Authorization\Service\AdminRightResolverInterface;
use OxidEsales\PaymentBase\Payment\AdminRightCheckInterface;

/**
 * The admin API's right resolver for the classic admin's money actions. The module is optional (`@?`): without it
 * there is no rights catalogue to ask, and the OXID admin login the classic admin already needs is the gate, as it
 * was before the catalogue existed.
 *
 * @since 1.7.0
 */
final class OxidAdminRightCheck implements AdminRightCheckInterface
{
    public function __construct(private readonly ?AdminRightResolverInterface $resolver)
    {
    }

    public function allows(string $userId, int $shopId, string $right): bool
    {
        return $this->resolver === null || $this->resolver->allows(new AdminIdentity($userId, $shopId), $right);
    }
}
