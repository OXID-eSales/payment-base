<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\Contract\CheckoutNoticeRelocatorInterface;
use Throwable;

/**
 * Session-backed {@see CheckoutNoticeRelocatorInterface}.
 *
 * The stash is `Errors` in the session: destination => list of serialized
 * IDisplayError objects. ShopControl reads it *after* the controller has
 * rendered, which is what makes taking it from a controller work — the shop
 * then finds nothing left to paint.
 *
 * The session reads and writes sit behind protected seams so the emptying rule
 * stays unit-testable without the shop bootstrap.
 */
class SessionCheckoutNoticeRelocator implements CheckoutNoticeRelocatorInterface
{
    private const STASH = 'Errors';

    private const DESTINATION = 'default';

    public function takeDisplayNotices(): array
    {
        try {
            $stash = $this->readErrors();
        } catch (Throwable) {
            // No session at all. The customer is owed their confirmation page
            // far more than they are owed this notice.
            return [];
        }

        if (!is_array($stash) || !isset($stash[self::DESTINATION]) || !is_array($stash[self::DESTINATION])) {
            return [];
        }

        $queued = $stash[self::DESTINATION];
        if ($queued === []) {
            // Nothing to take, so nothing to write back either.
            return [];
        }

        $notices = [];
        foreach ($queued as $entry) {
            $message = $this->readMessage($entry);
            if ($message !== null) {
                $notices[] = $message;
            }
        }

        // Emptied even when every entry was unreadable: leaving them behind
        // would show the customer the red banner this exists to remove, with
        // a message we could not read well enough to relocate.
        $stash[self::DESTINATION] = [];
        $this->writeErrors($stash);

        return $notices;
    }

    /**
     * One stash entry as its translated text, or null when it cannot be read.
     *
     * Duck-typed rather than checked against IDisplayError: the stash is
     * whatever the shop and its modules put there, and the only thing needed
     * here is a message.
     */
    private function readMessage(mixed $entry): ?string
    {
        if (!is_string($entry)) {
            return null;
        }

        $error = $this->unserializeQuietly($entry);
        if ($error === false) {
            return null;
        }

        if (!is_object($error) || !method_exists($error, 'getOxMessage')) {
            return null;
        }

        try {
            $message = $error->getOxMessage();
        } catch (Throwable) {
            return null;
        }

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * unserialize() reports malformed input with a PHP warning rather than an
     * exception, and malformed session data is an expected input here — a
     * try/catch never sees it, and left alone it would be logged for every such
     * entry. A handler scoped to this one call swallows exactly that, without
     * the '@' operator's blanket silence.
     */
    private function unserializeQuietly(string $entry): mixed
    {
        set_error_handler(static fn (int $severity, string $message): bool => true);

        try {
            return unserialize($entry);
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    protected function readErrors(): mixed
    {
        return Registry::getSession()->getVariable(self::STASH);
    }

    /**
     * @param array<array-key, mixed> $errors
     */
    protected function writeErrors(array $errors): void
    {
        Registry::getSession()->setVariable(self::STASH, $errors);
    }
}
