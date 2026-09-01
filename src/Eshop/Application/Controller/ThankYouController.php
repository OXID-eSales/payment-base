<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Controller;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Checkout\Contract\CheckoutNoticeRelocatorInterface;
use Throwable;

/**
 * A payment still settling is not an error.
 *
 * A PSP that returns the customer while the payment is still open has one
 * channel for saying so — `UtilsView::addErrorToDisplay()` — and the shop
 * paints everything in that channel as a dismissible red alert above the page.
 * The customer's order went through, and the first thing they see is red.
 *
 * This takes those messages out of the stash while the page renders, so they
 * can be shown inside the thank-you text instead. ShopControl reads the stash
 * *after* the controller has rendered, so by then there is nothing left for it
 * to paint.
 *
 * Provider-agnostic on purpose: it is the page that decides how a message on it
 * is framed, not each PSP. Any module that queues a message on its way to the
 * thank-you page gets it presented as a notice, without knowing this exists.
 *
 * @since 2026-09-01
 */
// @phpstan-ignore-next-line (ThankYouController_parent is an OXID virtual class generated at activation)
class ThankYouController extends ThankYouController_parent
{
    /** @var array<int, string>|null */
    private ?array $paymentNotices = null;

    public function render(): string
    {
        // Before parent::render(), because taking the messages is what keeps
        // them out of the red banner, and nothing downstream needs them there.
        $this->takePaymentNotices();

        return (string) parent::render();
    }

    /**
     * Template getter — the messages queued for this page, translated, to be
     * rendered inside the thank-you text.
     *
     * @return array<int, string>
     */
    public function getPaymentNotices(): array
    {
        return $this->paymentNotices ?? [];
    }

    /**
     * Memoised: the stash is emptied by the first read, so a second call would
     * answer nothing and silently lose the notice for the template.
     */
    protected function takePaymentNotices(): void
    {
        if ($this->paymentNotices !== null) {
            return;
        }

        try {
            $this->paymentNotices = $this->getNoticeRelocator()->takeDisplayNotices();
        } catch (Throwable) {
            // A confirmation page that renders without its notice beats one
            // that does not render at all.
            $this->paymentNotices = [];
        }
    }

    protected function getNoticeRelocator(): CheckoutNoticeRelocatorInterface
    {
        /** @var CheckoutNoticeRelocatorInterface $relocator */
        $relocator = ContainerFactory::getInstance()
            ->getContainer()
            ->get(CheckoutNoticeRelocatorInterface::class);

        return $relocator;
    }
}
