<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\NotFoundError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;

/**
 * The admin API's error vocabulary for a refusal of the payment service: a missing payment is its NotFoundError,
 * everything else a ValidationError with the field and a stable code.
 *
 * @since 1.5.0
 */
final class AdminPaymentErrors
{
    public static function from(PaymentRefusal $refusal): AdminError
    {
        if ($refusal->kind === PaymentRefusal::NOT_FOUND) {
            return new NotFoundError($refusal->message);
        }

        return new ValidationError($refusal->field, $refusal->code, $refusal->message);
    }
}
