<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership;
use OxidEsales\PaymentBase\GraphQL\Admin\Contract\PaymentOwnershipProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Claims the payment rows of the provider modules for the admin API (G09): the row of a provider belongs to its
 * module, so the admin can neither delete it nor change what the module writes into it (title and description,
 * which the module's definitions set at activation). Everything else of the row - active, sort, surcharge,
 * basket range, the assignments - stays the admin's.
 *
 * There is ONE payment method per provider; funding sources of a provider are never rows, so a row id that no
 * provider lists is not claimed.
 *
 * The ids are contributed by the provider modules (tag `oe.payment.ownership_provider`, VR-24 H6): payment-base
 * is the module every provider shares and names none of them. A provider that does not tag its rows leaves them
 * unlocked.
 *
 * @since 1.5.0
 */
final class PaymentOwnershipSubscriber implements EventSubscriberInterface
{
    private const CONTROLLED_FIELDS = ['title', 'description'];

    /**
     * @param iterable<PaymentOwnershipProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [AdminPaymentOwnership::class => 'onOwnership'];
    }

    public function onOwnership(AdminPaymentOwnership $event): void
    {
        foreach ($this->providers as $provider) {
            if (in_array($event->paymentId, $provider->paymentIds(), true)) {
                $event->claim($provider->providerName(), self::CONTROLLED_FIELDS);

                return;
            }
        }
    }
}
