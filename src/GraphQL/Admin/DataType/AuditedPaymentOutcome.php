<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;

/**
 * What the audit recorder is shown of an operation's result: the recorder reads success or the first error code
 * from a PayloadWithErrors, so a refusal is recorded as the failure it is.
 *
 * @since 1.5.0
 */
final class AuditedPaymentOutcome implements PayloadWithErrors
{
    public function __construct(private readonly PaymentOperationResult $result)
    {
    }

    public function errors(): array
    {
        $refusal = $this->result->refusal();

        return $refusal === null ? [] : [AdminPaymentErrors::from($refusal)];
    }
}
