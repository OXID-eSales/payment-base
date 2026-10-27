<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * The answer of capture, refund and void: the payment as it stands afterwards, or the domain errors.
 * Authentication, authorisation and step-up failures are GraphQL errors, as everywhere in the admin API.
 *
 * @since 1.5.0
 */
#[Type(name: 'AdminPaymentOperationPayload')]
final class AdminPaymentOperationPayload implements PayloadWithErrors
{
    /**
     * @param AdminError[] $errors
     */
    private function __construct(private readonly ?AdminPaymentContract $paymentContract, private readonly array $errors)
    {
    }

    public static function of(PaymentOperationResult $result): self
    {
        $refusal = $result->refusal();
        if ($refusal !== null) {
            return new self(null, [AdminPaymentErrors::from($refusal)]);
        }

        return new self(new AdminPaymentContract($result->payment()), []);
    }

    #[Field]
    public function paymentContract(): ?AdminPaymentContract
    {
        return $this->paymentContract;
    }

    /**
     * @return AdminError[]
     */
    #[Field]
    public function errors(): array
    {
        return $this->errors;
    }
}
