<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentOperationPayload;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperationsInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * `adminPaymentCapture`, `adminPaymentRefund`, `adminPaymentVoid`: the money operations of the Payment tab for the
 * admin API, in front of the provider modules' own capture, refund and cancel code. GraphQL mapping only; the
 * rules, step-up, idempotency and audit are in AdminPaymentOperations. #[Right] without #[Logged], like every
 * admin operation, so that a refusal carries an errorCode.
 *
 * `idempotencyKey` (8-64 characters of letters, digits, - and _) makes a retry safe: the same key reports the
 * earlier success instead of moving money again.
 *
 * @since 1.5.0
 */
final class PaymentMutations
{
    public function __construct(private readonly AdminPaymentOperationsInterface $operations)
    {
    }

    /**
     * Captures an authorised payment; without `amount` the full authorisation. Needs a step-up.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CAPTURE)]
    public function adminPaymentCapture(ID $orderId, ?float $amount = null, ?string $idempotencyKey = null): AdminPaymentOperationPayload
    {
        return AdminPaymentOperationPayload::of($this->operations->capture((string) $orderId, $amount, $idempotencyKey));
    }

    /**
     * Refunds part or all of what was captured. Needs a step-up.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::REFUND)]
    public function adminPaymentRefund(ID $orderId, float $amount, ?string $reason = null, ?string $idempotencyKey = null): AdminPaymentOperationPayload
    {
        return AdminPaymentOperationPayload::of($this->operations->refund((string) $orderId, $amount, $reason, $idempotencyKey));
    }

    /**
     * Releases an open authorisation (nothing was captured). Guarded by the capture right: it is the reverse of one.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CAPTURE)]
    public function adminPaymentVoid(ID $orderId, ?string $idempotencyKey = null): AdminPaymentOperationPayload
    {
        return AdminPaymentOperationPayload::of($this->operations->void((string) $orderId, $idempotencyKey));
    }
}
