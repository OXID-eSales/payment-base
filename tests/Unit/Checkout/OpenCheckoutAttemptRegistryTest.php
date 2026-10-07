<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Checkout\Context\PersistedCheckoutContext;
use OxidEsales\PaymentBase\Checkout\Context\SessionCheckoutContext;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\Context\InMemoryCheckoutContextStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Same session" is the whole point: an attempt left open in ANOTHER session or
 * on another device may still be paid, so only what this session opened may be
 * cleaned up. The shop session is therefore where the open attempt is recorded.
 */
final class OpenCheckoutAttemptRegistryTest extends TestCase
{
    /**
     * Sprint 15 / S2: the registry reads a CheckoutContextInterface. For the
     * Twig checkout that is the session (these tests), for a headless one the
     * persisted context - see {@see testBehavesTheSameOnEveryContext}.
     */
    private function sessionHolding(mixed $stored): CheckoutContextInterface
    {
        return new SessionCheckoutContext($this->sessionAdapterHolding($stored));
    }

    private function sessionAdapterHolding(mixed $stored): SessionAdapterInterface
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

    public function testPeekingAtTheOpenAttemptKeepsIt(): void
    {
        // MOL-18: the in-flight resolver only asks; the retire-and-recreate
        // path must still find the attempt afterwards.
        $session = $this->sessionHolding('contract-1');
        $registry = new OpenCheckoutAttemptRegistry($session);

        $this->assertSame('contract-1', $registry->peek());
        $this->assertSame('contract-1', $registry->peek());
        $this->assertSame('contract-1', $registry->takePrevious());
    }

    public function testPeekReportsNoOpenAttemptForAFreshSession(): void
    {
        $this->assertNull((new OpenCheckoutAttemptRegistry($this->sessionHolding(null)))->peek());
    }

    /**
     * @return iterable<string, array{CheckoutContextInterface}>
     */
    public static function everyContext(): iterable
    {
        yield 'session (Twig / OPC)' => [new SessionCheckoutContext(new RecordingSessionAdapter())];

        $scope = new HeadlessCheckoutScope();
        $scope->enter('contract-scope-1', userId: 'user-1', basketId: 'ub-1');
        yield 'persisted (headless)' => [new PersistedCheckoutContext($scope, new InMemoryCheckoutContextStore())];
    }

    /**
     * Sprint 15 / S2: remember → peek → take → forgotten, identically on the
     * session-backed and on the persisted context. The registry does not know
     * which one it got.
     */
    #[DataProvider('everyContext')]
    public function testBehavesTheSameOnEveryContext(CheckoutContextInterface $context): void
    {
        $registry = new OpenCheckoutAttemptRegistry($context);

        self::assertNull($registry->peek());

        $registry->remember('contract-1');
        self::assertSame('contract-1', $registry->peek());

        self::assertSame('contract-1', $registry->takePrevious());
        self::assertNull($registry->peek(), 'taken once, forgotten');
        self::assertNull($registry->takePrevious());
    }
}
