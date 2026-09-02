<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use PHPUnit\Framework\TestCase;

/**
 * "Same session" is the whole point: an attempt left open in ANOTHER session or
 * on another device may still be paid, so only what this session opened may be
 * cleaned up. The shop session is therefore where the open attempt is recorded.
 */
final class OpenCheckoutAttemptRegistryTest extends TestCase
{
    private function sessionHolding(mixed $stored): SessionAdapterInterface
    {
        return new class ($stored) implements SessionAdapterInterface {
            /** @var array<string, mixed> */
            public array $vars = [];

            public function __construct(mixed $stored)
            {
                $this->vars[OpenCheckoutAttemptRegistry::SESSION_KEY] = $stored;
            }

            public function getSessionId(): string
            {
                return 'session-1';
            }

            public function getBasket(): ?object
            {
                return null;
            }

            public function setVariable(string $name, mixed $value): void
            {
                $this->vars[$name] = $value;
            }

            public function getVariable(string $name): mixed
            {
                return $this->vars[$name] ?? null;
            }

            public function setBasket(object $basket): void
            {
            }

            public function setUser(object $user): void
            {
            }
        };
    }

    public function testRemembersTheAttemptThisSessionOpened(): void
    {
        $session = $this->sessionHolding(null);
        $registry = new OpenCheckoutAttemptRegistry($session);

        $registry->remember('contract-1');

        $this->assertSame('contract-1', $registry->takePrevious());
    }

    public function testTakingThePreviousAttemptClearsIt(): void
    {
        // Cleanup must not be attempted twice against the same contract - the
        // second pass would find it already cancelled and log a false alarm.
        $session = $this->sessionHolding('contract-1');
        $registry = new OpenCheckoutAttemptRegistry($session);

        $this->assertSame('contract-1', $registry->takePrevious());
        $this->assertNull($registry->takePrevious());
    }

    public function testReportsNoPreviousAttemptForAFreshSession(): void
    {
        $registry = new OpenCheckoutAttemptRegistry($this->sessionHolding(null));

        $this->assertNull($registry->takePrevious());
    }

    public function testIgnoresAValueThatIsNotAContractId(): void
    {
        $registry = new OpenCheckoutAttemptRegistry($this->sessionHolding(['not', 'a', 'string']));

        $this->assertNull($registry->takePrevious());
    }
}
