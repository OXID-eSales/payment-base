<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;

/**
 * Sprint 06 — shared plumbing for the two checkout controllers that ask the
 * single-payment question: one skips the selection step, the other hides the
 * payment block on the order page. Both must answer it identically, so both
 * ask the same resolver the same way.
 *
 * OXID controllers get no constructor injection, hence the container lookups.
 * They sit in protected methods so unit tests can replace them.
 */
trait ResolvesSinglePaymentMethod
{
    /**
     * @param array<array-key, mixed> $paymentList payment id => OXID Payment model
     */
    protected function resolveSinglePaymentIdFrom(array $paymentList): ?string
    {
        return $this->getSinglePaymentResolver()
            ->resolve(PaymentCandidateFactory::fromPaymentList($paymentList));
    }

    protected function getSinglePaymentResolver(): SinglePaymentResolverInterface
    {
        /** @var SinglePaymentResolverInterface $resolver */
        $resolver = ContainerFactory::getInstance()
            ->getContainer()
            ->get(SinglePaymentResolverInterface::class);

        return $resolver;
    }

    protected function getSinglePaymentSettings(): SinglePaymentSettingsInterface
    {
        /** @var SinglePaymentSettingsInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(SinglePaymentSettingsInterface::class);

        return $settings;
    }
}
