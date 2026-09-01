<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\CheckoutNoticeRelocatorInterface;
use OxidEsales\PaymentBase\Checkout\SessionCheckoutNoticeRelocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A message a PSP queues on its way to the thank-you page.
 *
 * Mirrors OXID's DisplayError closely enough for the relocator: the only thing
 * asked of it is to render itself, translated.
 */
class FakeDisplayError
{
    public function __construct(private readonly string $message)
    {
    }

    public function getOxMessage(): string
    {
        return $this->message;
    }
}

/**
 * Takes the messages a PSP queued for the thank-you page out of OXID's display
 * error stash, so the page can present them as what they are.
 *
 * A payment left pending on return is not an error — the order is placed and
 * the webhook will confirm it — but `addErrorToDisplay()` is the only channel
 * the shop offers, and it paints everything in it red at the top of the page.
 */
final class SessionCheckoutNoticeRelocatorTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(CheckoutNoticeRelocatorInterface::class, new SessionCheckoutNoticeRelocator());
    }

    public function testTakesTheQueuedMessagesTranslated(): void
    {
        $relocator = $this->relocatorSeeing([
            'default' => [serialize(new FakeDisplayError('payment is being processed'))],
        ]);

        $this->assertSame(['payment is being processed'], $relocator->takeDisplayNotices());
    }

    public function testKeepsTheQueueOrder(): void
    {
        $relocator = $this->relocatorSeeing([
            'default' => [
                serialize(new FakeDisplayError('first')),
                serialize(new FakeDisplayError('second')),
            ],
        ]);

        $this->assertSame(['first', 'second'], $relocator->takeDisplayNotices());
    }

    /**
     * Taking them is what stops the red banner: whatever is left in the stash
     * is what the shop paints at the top of the page.
     */
    public function testEmptiesTheDefaultDestinationSoNothingIsPaintedRed(): void
    {
        $relocator = $this->relocatorSeeing([
            'default' => [serialize(new FakeDisplayError('processing'))],
        ]);

        $relocator->takeDisplayNotices();

        $this->assertSame([], $relocator->written['default'] ?? ['not written']);
    }

    /**
     * Only the default destination is ours. `popup` and `loginBoxErrors` are
     * rendered by other parts of the page and belong to whoever queued them.
     */
    public function testLeavesOtherDestinationsAlone(): void
    {
        $popup = serialize(new FakeDisplayError('a modal message'));
        $relocator = $this->relocatorSeeing([
            'default' => [serialize(new FakeDisplayError('processing'))],
            'popup' => [$popup],
        ]);

        $relocator->takeDisplayNotices();

        $this->assertSame([$popup], $relocator->written['popup']);
    }

    public function testReturnsNothingWhenTheStashIsEmpty(): void
    {
        $this->assertSame([], $this->relocatorSeeing([])->takeDisplayNotices());
        $this->assertSame([], $this->relocatorSeeing(['default' => []])->takeDisplayNotices());
    }

    /**
     * Nothing queued must not cost a session write — a needless write on every
     * thank-you page is the kind of thing that only shows up under load.
     */
    public function testDoesNotWriteWhenThereWasNothingToTake(): void
    {
        $relocator = $this->relocatorSeeing([]);

        $relocator->takeDisplayNotices();

        $this->assertNull($relocator->written, 'the session must not be written when nothing changed');
    }

    /**
     * The stash is session data. Anything unreadable in it is skipped rather
     * than allowed to take down the page that tells the customer their order
     * went through.
     */
    public function testSkipsEntriesItCannotRead(): void
    {
        $relocator = $this->relocatorSeeing([
            'default' => [
                'not-serialized-at-all',
                serialize(['an', 'array']),
                serialize(new FakeDisplayError('the real one')),
            ],
        ]);

        $this->assertSame(['the real one'], $relocator->takeDisplayNotices());
    }

    public function testSurvivesAStashThatIsNotAnArray(): void
    {
        $relocator = $this->relocatorSeeing('nonsense');

        $this->assertSame([], $relocator->takeDisplayNotices());
    }

    /**
     * No session (CLI, a broken bootstrap) must not cost the customer their
     * confirmation page.
     */
    public function testReturnsNothingWhenTheSessionCannotBeRead(): void
    {
        $relocator = new class () extends SessionCheckoutNoticeRelocator {
            protected function readErrors(): mixed
            {
                throw new RuntimeException('no session');
            }
        };

        $this->assertSame([], $relocator->takeDisplayNotices());
    }

    private function relocatorSeeing(mixed $errors): SessionCheckoutNoticeRelocator
    {
        return new class ($errors) extends SessionCheckoutNoticeRelocator {
            /** @var array<array-key, mixed>|null */
            public ?array $written = null;

            public function __construct(private readonly mixed $errors)
            {
            }

            protected function readErrors(): mixed
            {
                return $this->errors;
            }

            /** @param array<array-key, mixed> $errors */
            protected function writeErrors(array $errors): void
            {
                $this->written = $errors;
            }
        };
    }
}
