<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;

/**
 * Contracts in the lifecycle states the admin payment API distinguishes, built through the domain's own
 * transitions (never by poking state).
 */
final class ContractFactory
{
    public const SHOP_ID = 1;

    public static function pending(string $orderId, string $provider = 'paypal', float $gross = 100.0): PaymentContract
    {
        $contract = new PaymentContract(self::SHOP_ID, 'user-1', self::snapshot($gross));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider($provider, 'prov-' . $orderId);

        return $contract;
    }

    public static function authorized(string $orderId, string $provider = 'paypal', float $gross = 100.0): PaymentContract
    {
        $contract = self::pending($orderId, $provider, $gross);
        $contract->authorize();

        return $contract;
    }

    /** Committed with nothing captured: the manual-capture providers that commit on authorisation. */
    public static function committedUncaptured(string $orderId, string $provider = 'stripe', float $gross = 100.0): PaymentContract
    {
        $contract = self::authorized($orderId, $provider, $gross);
        $contract->captureAuthorization();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);

        return $contract;
    }

    public static function fulfilled(
        string $orderId,
        string $provider = 'paypal',
        float $gross = 100.0,
        float $captured = 100.0,
        float $refunded = 0.0,
    ): PaymentContract {
        $contract = self::committedUncaptured($orderId, $provider, $gross);
        $contract->setCapturedAmount($captured);
        $contract->fulfill();
        if ($refunded > 0.0) {
            $contract->addRefundedAmount($refunded);
        }

        return $contract;
    }

    private static function snapshot(float $gross): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => $gross,
            'totalNet' => round($gross / 1.19, 2),
            'totalVat' => round($gross - $gross / 1.19, 2),
            'currency' => 'EUR',
        ]);
    }
}
