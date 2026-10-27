<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use Closure;
use LogicException;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminAudit;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use PHPUnit\Framework\TestCase;

final class OxidAdminAuditTest extends TestCase
{
    public function testAudited_RunsTheOperationInsideTheRecorder_NamingEntityFieldsAndAmount(): void
    {
        $seen = new \stdClass();
        $audit = new OxidAdminAudit($this->recorderCapturing($seen));

        $result = $audit->audited('adminPaymentRefund', 'order-1', ['amount', 'reason'], 12.5, fn (): PaymentOperationResult => $this->done());

        self::assertNull($result->refusal());
        self::assertSame('adminPaymentRefund', $seen->operation);
        self::assertSame(['order', 'order-1'], [$seen->trail->entityType, $seen->trail->entityId]);
        self::assertSame(['amount', 'reason'], $seen->trail->fields);
        self::assertSame(['amount' => 12.5], $seen->trail->values, 'the amount is the only value; the reason is a field name');
    }

    public function testAudited_NoAmount_RecordsNoValue(): void
    {
        $seen = new \stdClass();

        (new OxidAdminAudit($this->recorderCapturing($seen)))
            ->audited('adminPaymentVoid', 'order-1', [], null, fn (): PaymentOperationResult => $this->done());

        self::assertSame([], $seen->trail->values);
    }

    public function testAudited_ARefusalIsReportedToTheRecorderAsAnErrorOfThePayload_AndReturnedUnchanged(): void
    {
        $seen = new \stdClass();
        $refused = PaymentOperationResult::refused(PaymentRefusal::invalid('amount', 'exceeds_refundable', 'too much'));

        $result = (new OxidAdminAudit($this->recorderCapturing($seen)))
            ->audited('adminPaymentRefund', 'order-1', ['amount'], 99.0, fn (): PaymentOperationResult => $refused);

        self::assertSame($refused, $result);
        self::assertInstanceOf(PayloadWithErrors::class, $seen->returned);
        self::assertCount(1, $seen->returned->errors());
        self::assertSame('validation_failed', $seen->returned->errors()[0]->errorCode());
    }

    public function testAudited_ASuccessIsAPayloadWithoutErrors(): void
    {
        $seen = new \stdClass();

        (new OxidAdminAudit($this->recorderCapturing($seen)))
            ->audited('adminPaymentCapture', 'order-1', [], null, fn (): PaymentOperationResult => $this->done());

        self::assertSame([], $seen->returned->errors());
    }

    public function testAudited_AnExceptionPassesThroughTheRecorder(): void
    {
        $audit = new OxidAdminAudit($this->recorderCapturing(new \stdClass()));

        $this->expectExceptionMessage('step_up_required');

        $audit->audited('adminPaymentRefund', 'order-1', [], null, static function (): PaymentOperationResult {
            throw new \RuntimeException('step_up_required');
        });
    }

    public function testAudited_NotANumberAmountIsNotRecorded(): void
    {
        $seen = new \stdClass();

        (new OxidAdminAudit($this->recorderCapturing($seen)))
            ->audited('adminPaymentRefund', 'order-1', ['amount'], NAN, fn (): PaymentOperationResult => $this->done());

        self::assertSame([], $seen->trail->values);
    }

    public function testAudited_WithoutTheAdminModule_FailsClosed(): void
    {
        $this->expectException(LogicException::class);

        (new OxidAdminAudit(null))->audited('x', 'o', [], null, fn (): PaymentOperationResult => $this->done());
    }

    private function recorderCapturing(\stdClass $seen): AuditRecorder
    {
        return new class ($seen) extends AuditRecorder {
            public function __construct(private readonly \stdClass $seen)
            {
            }

            public function auditedAdmin(string $operation, Closure $action): mixed
            {
                $trail = new AuditTrail($operation);
                $returned = $action($trail);
                $this->seen->operation = $operation;
                $this->seen->trail = $trail;
                $this->seen->returned = $returned;

                return $returned;
            }
        };
    }

    private function done(): PaymentOperationResult
    {
        return PaymentOperationResult::done(new PaymentContractDetails('c', 'paypal', 'fulfilled', 1.0, 1.0, 0.0, 'EUR', []));
    }
}
