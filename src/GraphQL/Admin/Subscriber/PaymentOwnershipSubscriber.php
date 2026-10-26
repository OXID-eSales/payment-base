<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Claims the payment rows of the provider modules for the admin API (G09): the row of a provider belongs to its
 * module, so the admin can neither delete it nor change what the module writes into it (title and description,
 * which the module's definitions set at activation). Everything else of the row - active, sort, surcharge,
 * basket range, the assignments - stays the admin's.
 *
 * There is ONE payment method per provider; PayPal's funding sources (Pay Later, card, ...) are never rows, so
 * a row id that is not listed here is not claimed.
 *
 * The ids are written out here, not collected from the provider modules: payment-base is the module every
 * provider shares, and its admin API is what makes the lock real for all of them at once.
 *
 * @since 1.5.0
 */
final class PaymentOwnershipSubscriber implements EventSubscriberInterface
{
    /** payment id => the provider name the contracts record */
    private const PROVIDER_ROWS = [
        'oe_payments_stripe_wallet' => 'stripe',
        'oe_payments_paypal' => 'paypal',
        'oe_payments_mollie' => 'mollie',
    ];

    private const CONTROLLED_FIELDS = ['title', 'description'];

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [AdminPaymentOwnership::class => 'onOwnership'];
    }

    public function onOwnership(AdminPaymentOwnership $event): void
    {
        $provider = self::PROVIDER_ROWS[$event->paymentId] ?? null;
        if ($provider === null) {
            return;
        }
        $event->claim($provider, self::CONTROLLED_FIELDS);
    }
}
