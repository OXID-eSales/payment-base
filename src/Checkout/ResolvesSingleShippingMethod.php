<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingSettingsInterface;

/**
 * Sprint 07 — shared plumbing for the two checkout controllers that ask the
 * single-shipping question: one hides the delivery-set selector on the payment
 * step, the other hides the carrier block on the order page. Both must answer
 * it identically, so both ask the same resolver the same way.
 *
 * OXID controllers get no constructor injection, hence the container lookups.
 * They sit in protected methods so unit tests can replace them.
 */
trait ResolvesSingleShippingMethod
{
    /**
     * @param array<array-key, mixed> $deliverySetList delivery-set id => OXID DeliverySet model
     */
    protected function resolveSingleShipSetIdFrom(array $deliverySetList): ?string
    {
        return $this->getSingleShippingResolver()
            ->resolve(ShippingCandidateFactory::fromDeliverySetList($deliverySetList));
    }

    protected function getSingleShippingResolver(): SingleShippingResolverInterface
    {
        /** @var SingleShippingResolverInterface $resolver */
        $resolver = ContainerFactory::getInstance()
            ->getContainer()
            ->get(SingleShippingResolverInterface::class);

        return $resolver;
    }

    protected function getSingleShippingSettings(): SingleShippingSettingsInterface
    {
        /** @var SingleShippingSettingsInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(SingleShippingSettingsInterface::class);

        return $settings;
    }
}
