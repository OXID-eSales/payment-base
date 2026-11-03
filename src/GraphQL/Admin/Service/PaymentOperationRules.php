<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;

/**
 * What the free text of an admin money call must satisfy: the provider's own limit and, where it declares a list,
 * the only reasons it takes. The amount rules are the policies' (VR-22 A3: payment-base owns them).
 *
 * @since 1.5.0
 */
final class PaymentOperationRules
{
    /**
     * The provider's own say on the free text: its limit, and, where it declares a list, the only reasons it takes.
     */
    public static function textRefusal(AdminPaymentOperation $operation, AdminActionOptions $options): ?PaymentRefusal
    {
        $limit = $options->reasonMaxLength;
        foreach (['reason' => $operation->reason, 'description' => $operation->description] as $field => $text) {
            if ($text !== null && strlen($text) > $limit) {
                return PaymentRefusal::invalid($field, 'too_long', sprintf('The %s is longer than %d characters', $field, $limit));
            }
        }
        $allowed = match ($operation->kind) {
            AdminPaymentOperation::REFUND => $options->refundReasons,
            AdminPaymentOperation::VOID => $options->voidReasons,
            default => [],
        };
        if ($operation->reason === null || $allowed === [] || in_array($operation->reason, $allowed, true)) {
            return null;
        }

        return PaymentRefusal::invalid('reason', 'reason_invalid', 'The reason is not one the payment provider accepts');
    }
}
