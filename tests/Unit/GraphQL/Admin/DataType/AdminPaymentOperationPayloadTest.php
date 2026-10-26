<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\NotFoundError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentOperationPayload;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use PHPUnit\Framework\TestCase;

final class AdminPaymentOperationPayloadTest extends TestCase
{
    public function testOf_DoneResult_CarriesTheContractAndNoErrors(): void
    {
        $payload = AdminPaymentOperationPayload::of(PaymentOperationResult::done(
            new PaymentContractDetails('c', 'paypal', 'fulfilled', 10.0, 10.0, 0.0, 'EUR', []),
        ));

        self::assertSame('FULFILLED', $payload->paymentContract()?->state());
        self::assertSame([], $payload->errors());
    }

    public function testOf_NotFound_BecomesNotFoundError(): void
    {
        $payload = AdminPaymentOperationPayload::of(PaymentOperationResult::refused(PaymentRefusal::notFound()));

        self::assertNull($payload->paymentContract());
        self::assertCount(1, $payload->errors());
        self::assertInstanceOf(NotFoundError::class, $payload->errors()[0]);
    }

    public function testOf_Invalid_BecomesValidationErrorWithFieldAndCode(): void
    {
        $payload = AdminPaymentOperationPayload::of(PaymentOperationResult::refused(
            PaymentRefusal::invalid('amount', 'exceeds_refundable', 'The amount is more than what is left to refund'),
        ));

        $error = $payload->errors()[0];
        self::assertInstanceOf(ValidationError::class, $error);
        self::assertSame(['amount', 'exceeds_refundable'], [$error->field(), $error->code()]);
        self::assertSame('The amount is more than what is left to refund', $error->message());
    }

    public function testImplementsThePayloadContractOfTheAdminApi(): void
    {
        self::assertContains(
            \OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors::class,
            (new \ReflectionClass(AdminPaymentOperationPayload::class))->getInterfaceNames(),
        );
    }
}
