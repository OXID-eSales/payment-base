<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\AdminSession\Service\CurrentAdminSession;
use OxidEsales\GraphQL\Admin\Authorization\DataType\Role;
use OxidEsales\GraphQL\Admin\Authorization\Infrastructure\RoleRepositoryInterface;
use OxidEsales\GraphQL\Admin\Authorization\Infrastructure\UserRoleRepositoryInterface;
use Throwable;

/**
 * Asks the admin module's roles: the user of the request's admin session holds the built-in superadmin role (the
 * role decides itself, see Role::holdsEverything). The module is optional (services wired with `@?`); without it,
 * without a valid session or on any failure the answer is false, so a secret never changes by accident.
 *
 * @since 1.7.0
 */
final class OxidAdminSuperadminCheck implements AdminSuperadminCheckInterface
{
    public function __construct(
        private readonly ?CurrentAdminSession $currentSession,
        private readonly ?UserRoleRepositoryInterface $assignments,
        private readonly ?RoleRepositoryInterface $roles
    ) {
    }

    public function isSuperadmin(): bool
    {
        if ($this->currentSession === null || $this->assignments === null || $this->roles === null) {
            return false;
        }

        try {
            $roleIds = $this->assignments->roleIdsOf($this->currentSession->current()->userId);
            foreach ($this->roles->findVisibleByIds($roleIds) as $role) {
                if ($role instanceof Role && $role->holdsEverything()) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
