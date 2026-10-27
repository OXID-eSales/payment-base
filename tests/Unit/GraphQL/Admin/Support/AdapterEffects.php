<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use Closure;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

/**
 * What a provider adapter does to the contract when it succeeds, in the domain's own transitions. The three
 * providers record capture, refund and cancel on the contract exactly like this (CaptureService /
 * ContractRefundRecorder / the cancel handler).
 */
final class AdapterEffects
{
    /**
     * @param ContractRepositoryInterface|null $persistTo where the adapter saves the contract (the providers do); none: the
     *                                                    instance the test holds is the stored one
     *
     * @return Closure(string, array<string, mixed>, PaymentPanelContext): void
     */
    public static function successful(?ContractRepositoryInterface $persistTo = null): Closure
    {
        return static function (string $action, array $request, PaymentPanelContext $context) use ($persistTo): void {
            $contract = $context->contract;
            if ($contract === null) {
                return;
            }
            match ($action) {
                'capture' => self::capture($contract, (float) ($request['capture_amount'] ?? $contract->getAmount())),
                'refund' => $contract->addRefundedAmount((float) $request['refund_amount']),
                'cancel' => $contract->cancel('voided'),
                default => null,
            };
            $persistTo?->save($contract);
        };
    }

    /**
     * The adapter refused silently, as the classic tab's validation does (the reason goes to its own feedback).
     *
     * @return Closure(string, array<string, mixed>, PaymentPanelContext): void
     */
    public static function silentlyRefusing(): Closure
    {
        return static function (): void {
        };
    }

    private static function capture(PaymentContractInterface $contract, float $amount): void
    {
        $contract->captureAuthorization();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder((string) $contract->getOrderId());
        $contract->setCapturedAmount($amount);
        $contract->fulfill();
    }
}
