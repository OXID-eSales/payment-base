<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Validation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use OxidEsales\PaymentBase\Validation\Guard\CsrfTokenGuard;
use OxidEsales\PaymentBase\Validation\Guard\GuardFailure;
use OxidEsales\PaymentBase\Validation\Guard\RateLimitGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationGuardInterface;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\RateLimit\DoctrineRateLimitStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL) — Mythos O-08 on the container's own guard chain and the real oe_payments_idempotency table.
 *
 * The chain runs from the first guard after the CSRF check: the guards before it need a browser session
 * (stoken, same origin) and are covered by their unit tests; O-08 starts once a visitor has passed them.
 */
#[Group('integration')]
final class ValidationGuardChainTest extends TestCase
{
    private const TABLE = 'oe_payments_idempotency';

    private Connection $connection;

    private string $run;

    private string $clientIp;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)
            ->get();
        $this->run = 'vrl29-' . bin2hex(random_bytes(4));
        $this->clientIp = '2001:db8::' . dechex(random_int(1, 0xffff)) . ':' . dechex(random_int(1, 0xffff));
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE OXKEY LIKE :run OR OXKEY LIKE :client',
            ['run' => '%' . $this->run . '%', 'client' => '%' . sha1($this->clientIp) . '%']
        );
    }

    public function testInventedPluginIds_AreRejected_AndWriteNoRows(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $failure = $this->runChain($this->context($this->run . '-' . $i, 'sess-' . $i));

            self::assertNotNull($failure, 'call ' . $i);
            self::assertSame(422, $failure->httpStatus, 'call ' . $i);
        }

        self::assertSame(0, $this->rowsOfThisRun());
    }

    public function testFreshSessions_FromOneClient_AreLimited(): void
    {
        $guard = $this->rateLimitGuard();
        $limit = $this->configuredLimit();

        for ($i = 1; $i <= $limit; $i++) {
            self::assertNull($guard->check($this->context('any_plugin', 'fresh-session-' . $i)), 'call ' . $i);
        }
        $failure = $guard->check($this->context('any_plugin', 'fresh-session-' . ($limit + 1)));

        self::assertNotNull($failure, 'a fresh session must not start the count again');
        self::assertSame(429, $failure->httpStatus);
    }

    public function testExpiredRows_AreRemovedOnTheNextNewKey(): void
    {
        $this->seed('validate:' . $this->run . ':old', 'rate_limit', '5', '-10 minutes');
        $this->seed('admin_payment:refund:' . $this->run . ':old', 'completed', '{}', '-1 minute');
        $this->seed('admin_payment:refund:' . $this->run . ':live', 'completed', '{}', '+1 hour');

        $this->store()->increment('validate:' . $this->run . ':new', 60);

        self::assertFalse($this->exists('validate:' . $this->run . ':old'));
        self::assertFalse($this->exists('admin_payment:refund:' . $this->run . ':old'));
        self::assertTrue($this->exists('admin_payment:refund:' . $this->run . ':live'));
    }

    public function testAnExpiredKeyIsReused_AndStartsAtOne(): void
    {
        $key = 'validate:' . $this->run . ':reuse';
        $this->seed($key, 'rate_limit', '99', '-1 second');

        self::assertSame(1, $this->store()->increment($key, 60));
    }

    public function testALiveKeyCountsUp(): void
    {
        $key = 'validate:' . $this->run . ':live';
        $store = $this->store();

        self::assertSame(1, $store->increment($key, 60));
        self::assertSame(2, $store->increment($key, 60));
        self::assertSame(3, $store->increment($key, 60));
    }

    private function runChain(ValidationRequestContext $ctx): ?GuardFailure
    {
        foreach ($this->guardsAfterCsrf() as $guard) {
            $failure = $guard->check($ctx);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * @return list<ValidationGuardInterface>
     */
    private function guardsAfterCsrf(): array
    {
        $guards = [];
        $csrfPriority = 0;
        foreach ($this->allGuards() as $guard) {
            $guards[] = $guard;
            $csrfPriority = $guard instanceof CsrfTokenGuard ? $guard->getPriority() : $csrfPriority;
        }
        usort($guards, static fn (ValidationGuardInterface $a, ValidationGuardInterface $b): int => $a->getPriority() <=> $b->getPriority());

        return array_values(array_filter($guards, static fn (ValidationGuardInterface $g): bool => $g->getPriority() > $csrfPriority));
    }

    /**
     * @return iterable<ValidationGuardInterface>
     */
    private function allGuards(): iterable
    {
        /** @var iterable<ValidationGuardInterface> $guards */
        $guards = ContainerFactory::getInstance()->getContainer()->get('oe.payment_base.validation_guard_iterator');

        return $guards;
    }

    private function rateLimitGuard(): RateLimitGuard
    {
        foreach ($this->allGuards() as $guard) {
            if ($guard instanceof RateLimitGuard) {
                return $guard;
            }
        }
        self::fail('RateLimitGuard is not in the validation guard chain');
    }

    private function configuredLimit(): int
    {
        /** @var ModuleSettingServiceInterface $settings */
        $settings = ContainerFactory::getInstance()->getContainer()->get(ModuleSettingServiceInterface::class);

        return $settings->getInteger('iValidationApiRatePerMinute', 'oe_payment_base');
    }

    private function store(): DoctrineRateLimitStore
    {
        return new DoctrineRateLimitStore($this->connection, new DoctrineIdempotencyRepository($this->connection));
    }

    private function rowsOfThisRun(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXKEY LIKE :run OR OXKEY LIKE :client',
            ['run' => '%' . $this->run . '%', 'client' => '%' . sha1($this->clientIp) . '%']
        );
    }

    private function exists(string $key): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE OXKEY = :k', ['k' => $key]) !== false;
    }

    private function seed(string $key, string $status, string $result, string $expiresIn): void
    {
        $now = new DateTimeImmutable();
        $this->connection->insert(self::TABLE, [
            'OXID' => md5($key),
            'OXKEY' => $key,
            'OXORDERID' => '',
            'OXOPERATION' => $status === 'rate_limit' ? 'rate_limit' : 'refund',
            'OXRESULT' => $result,
            'OXSTATUS' => $status,
            'OXCREATED' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
            'OXEXPIRES' => $now->modify($expiresIn)->format('Y-m-d H:i:s'),
        ]);
    }

    private function context(string $pluginModuleId, string $sessionId): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: ['city' => 'x'],
            pluginModuleId: $pluginModuleId,
            csrfToken: 'token',
            sessionId: $sessionId,
            originHeader: null,
            refererHeader: null,
            clientIp: $this->clientIp,
        );
    }
}
