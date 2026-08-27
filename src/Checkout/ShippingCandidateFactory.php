<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\ShippingCandidate;
use Throwable;

/**
 * Turns OXID's delivery-set list into candidates the resolver can reason about.
 *
 * This is the only place that touches the shop's delivery-set models, which is
 * why every question asked of them is wrapped: the list may legitimately
 * contain a foreign module's model that answers differently, and a checkout
 * must never break over a carrier it merely failed to interrogate.
 *
 * Stateless and deterministic, hence static (see the module's static-utility
 * convention) — there is nothing here worth injecting.
 */
final class ShippingCandidateFactory
{
    /**
     * @param array<array-key, mixed> $deliverySetList delivery-set id => OXID DeliverySet model
     * @return list<ShippingCandidate>
     */
    public static function fromDeliverySetList(array $deliverySetList): array
    {
        $candidates = [];

        foreach ($deliverySetList as $key => $deliverySet) {
            $id = self::resolveId($key, $deliverySet);
            if ($id === null) {
                continue;
            }

            $candidates[] = new ShippingCandidate($id);
        }

        return $candidates;
    }

    /**
     * The array key is the delivery-set id in every core list. A numerically
     * indexed array carries no id, so the model is asked for its own — an id of
     * "0" would name a set that does not exist.
     */
    private static function resolveId(int|string $key, mixed $deliverySet): ?string
    {
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $id = self::readModelId($deliverySet);

        return $id === '' ? null : $id;
    }

    private static function readModelId(mixed $deliverySet): string
    {
        try {
            $id = $deliverySet->getId();
        } catch (Throwable) {
            return '';
        }

        return is_string($id) ? $id : '';
    }
}
