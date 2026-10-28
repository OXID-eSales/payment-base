<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\AdminSession\DataType\AdminSession;
use OxidEsales\GraphQL\Admin\AdminSession\Service\CurrentAdminSession;
use OxidEsales\GraphQL\Admin\Authorization\DataType\Role;
use OxidEsales\GraphQL\Admin\Authorization\Infrastructure\RoleRepositoryInterface;
use OxidEsales\GraphQL\Admin\Authorization\Infrastructure\UserRoleRepositoryInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminSuperadminCheck;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OxidAdminSuperadminCheckTest extends TestCase
{
    public function testIsSuperadmin_TheUserHoldsTheBuiltInSuperadminRole_True(): void
    {
        $check = $this->checkFor([$this->role('r1', 'superadmin', true)]);

        self::assertTrue($check->isSuperadmin());
    }

    public function testIsSuperadmin_OnlyACustomRole_False(): void
    {
        $check = $this->checkFor([$this->role('r2', 'payment_ops', false)]);

        self::assertFalse($check->isSuperadmin());
    }

    public function testIsSuperadmin_ACustomRoleThatMerelyIsCalledSuperadmin_False(): void
    {
        $check = $this->checkFor([$this->role('r3', 'superadmin', false)]);

        self::assertFalse($check->isSuperadmin());
    }

    public function testIsSuperadmin_NoRoles_False(): void
    {
        self::assertFalse($this->checkFor([])->isSuperadmin());
    }

    public function testIsSuperadmin_NoValidSession_FailsClosed(): void
    {
        $session = $this->createStub(CurrentAdminSession::class);
        $session->method('current')->willThrowException(new RuntimeException('no session'));

        $check = new OxidAdminSuperadminCheck($session, $this->createStub(UserRoleRepositoryInterface::class), $this->createStub(RoleRepositoryInterface::class));

        self::assertFalse($check->isSuperadmin());
    }

    public function testIsSuperadmin_WithoutTheAdminModule_FailsClosed(): void
    {
        self::assertFalse((new OxidAdminSuperadminCheck(null, null, null))->isSuperadmin());
    }

    /**
     * @param list<Role> $roles
     */
    private function checkFor(array $roles): OxidAdminSuperadminCheck
    {
        $session = $this->createStub(CurrentAdminSession::class);
        $session->method('current')->willReturn(new AdminSession('s1', 'user-1', 1));
        $assignments = $this->createMock(UserRoleRepositoryInterface::class);
        $assignments->method('roleIdsOf')->with('user-1')->willReturn(array_map(static fn (Role $role): string => $role->id, $roles));
        $repository = $this->createStub(RoleRepositoryInterface::class);
        $repository->method('findVisibleByIds')->willReturn($roles);

        return new OxidAdminSuperadminCheck($session, $assignments, $repository);
    }

    private function role(string $id, string $code, bool $builtin): Role
    {
        return new Role($id, $builtin ? 0 : 1, $code, $code, $builtin, [], '2026-10-28 10:00:00.000000');
    }
}
