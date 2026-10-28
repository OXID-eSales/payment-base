<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Admin\AdminSession\DataType\AdminSession;
use OxidEsales\GraphQL\Admin\AdminSession\DataType\ClientFingerprint;
use OxidEsales\GraphQL\Admin\AdminSession\DataType\RefreshState;
use OxidEsales\GraphQL\Admin\AdminSession\DataType\SessionLifetime;
use OxidEsales\GraphQL\Admin\AdminSession\Infrastructure\AccessTokenContextInterface;
use OxidEsales\GraphQL\Admin\AdminSession\Infrastructure\AdminSessionRepositoryInterface;
use OxidEsales\GraphQL\Admin\AdminSession\Service\AdminSessionGuard;
use OxidEsales\GraphQL\Admin\AdminSession\Service\AdminSessionSettings;
use OxidEsales\GraphQL\Admin\AdminSession\Service\CurrentAdminSession;
use OxidEsales\GraphQL\Admin\Authorization\DataType\Role;
use OxidEsales\GraphQL\Admin\Authorization\Infrastructure\RoleRepositoryInterface;
use OxidEsales\GraphQL\Admin\Authorization\Infrastructure\UserRoleRepositoryInterface;
use OxidEsales\GraphQL\Admin\Shared\Infrastructure\ModuleSettingsInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminSuperadminCheckInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminSuperadminCheck;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

/**
 * The superadmin check against the admin module's real role tables and real services: an admin with the built-in
 * superadmin role is one, an admin whose custom role holds ADMIN_PAYMENT_CONFIG (and everything else payment) is not.
 * Only the access token and the session store are stood in (a request carries them); the roles are the real rows.
 */
#[Group('integration')]
final class OxidAdminSuperadminCheckIntegrationTest extends IntegrationTestCase
{
    private const SHOP_ID = 1;
    private const SUPER_USER = 'pay10-superadmin';
    private const CONFIG_USER = 'pay10-config-admin';

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(CurrentAdminSession::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
    }

    public function testTheContainerWiresTheCheckToTheAdminModulesServices(): void
    {
        $check = ContainerFactory::getInstance()->getContainer()->get(AdminSuperadminCheckInterface::class);

        self::assertInstanceOf(OxidAdminSuperadminCheck::class, $check);
        self::assertFalse($check->isSuperadmin(), 'no admin session in this process: fails closed');
    }

    public function testAnAdminWithTheBuiltInSuperadminRole_IsASuperadmin(): void
    {
        $super = $this->roleRepository()->findVisibleByCode(Role::SUPERADMIN);
        self::assertNotNull($super);
        $this->assignments()->assign(self::SUPER_USER, $super->id);

        self::assertTrue($this->checkFor(self::SUPER_USER)->isSuperadmin());
    }

    public function testAnAdminWhoseCustomRoleHoldsAdminPaymentConfig_IsNotASuperadmin(): void
    {
        $custom = new Role(bin2hex(random_bytes(16)), self::SHOP_ID, 'pay10_config', 'PAY-10 config', false, ['ADMIN_PAYMENT_CONFIG', 'ADMIN_PAYMENT_VIEW'], gmdate(Role::TIMESTAMP_FORMAT));
        $this->roleRepository()->insert($custom);
        $this->assignments()->assign(self::CONFIG_USER, $custom->id);

        self::assertFalse($this->checkFor(self::CONFIG_USER)->isSuperadmin());
    }

    public function testAnAdminWithoutAnyRole_IsNotASuperadmin(): void
    {
        self::assertFalse($this->checkFor('pay10-nobody')->isSuperadmin());
    }

    private function checkFor(string $userId): OxidAdminSuperadminCheck
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $session = new AdminSession(
            'pay10-session-' . $userId,
            $userId,
            self::SHOP_ID,
            new SessionLifetime($now, $now, $now->modify('+15 minutes'), $now->modify('+8 hours')),
            new RefreshState('hash', null, 'family'),
            null,
            null,
            new ClientFingerprint(hash('sha256', '127.0.0.1'), hash('sha256', 'phpunit')),
            null,
        );
        $current = new CurrentAdminSession($this->tokenOf($userId, $session->id), $this->guardFor($session, $now));

        return new OxidAdminSuperadminCheck($current, $this->assignments(), $this->roleRepository());
    }

    private function guardFor(AdminSession $session, DateTimeImmutable $now): AdminSessionGuard
    {
        $sessions = $this->createStub(AdminSessionRepositoryInterface::class);
        $sessions->method('find')->willReturn($session);
        $clock = new class ($now) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $settings = new AdminSessionSettings($this->createStub(ModuleSettingsInterface::class));

        return new AdminSessionGuard($sessions, $clock, $settings);
    }

    private function tokenOf(string $userId, string $sessionId): AccessTokenContextInterface
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(str_repeat('k', 32)));
        $token = $config->builder()
            ->withClaim('userid', $userId)
            ->withClaim('shopid', self::SHOP_ID)
            ->withClaim('adm', 1)
            ->withClaim('asid', $sessionId)
            ->getToken($config->signer(), $config->signingKey());

        return new class ($token) implements AccessTokenContextInterface {
            public function __construct(private readonly Token $token)
            {
            }

            public function token(): Token
            {
                return $this->token;
            }
        };
    }

    private function roleRepository(): RoleRepositoryInterface
    {
        /** @var RoleRepositoryInterface $repository */
        $repository = ContainerFactory::getInstance()->getContainer()->get(RoleRepositoryInterface::class);

        return $repository;
    }

    private function assignments(): UserRoleRepositoryInterface
    {
        /** @var UserRoleRepositoryInterface $repository */
        $repository = ContainerFactory::getInstance()->getContainer()->get(UserRoleRepositoryInterface::class);

        return $repository;
    }
}
