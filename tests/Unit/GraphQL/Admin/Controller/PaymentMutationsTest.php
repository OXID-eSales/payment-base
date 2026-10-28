<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Controller\PaymentMutations;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperationsInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Types\ID;

final class PaymentMutationsTest extends TestCase
{
    private AdminPaymentOperationsInterface&MockObject $operations;

    protected function setUp(): void
    {
        $this->operations = $this->createMock(AdminPaymentOperationsInterface::class);
    }

    public function testAdminPaymentCapture_DelegatesAndAnswersThePaymentAfterwards(): void
    {
        $this->operations->expects(self::once())->method('capture')->with('order-1', 40.0, 'key-12345678', null)
            ->willReturn(PaymentOperationResult::done($this->payment('FULFILLED')));

        $payload = $this->mutations()->adminPaymentCapture(new ID('order-1'), 40.0, 'key-12345678');

        self::assertSame('FULFILLED', $payload->paymentContract()?->state());
        self::assertSame([], $payload->errors());
    }

    public function testAdminPaymentRefund_DelegatesWithAmountReasonAndKey(): void
    {
        $this->operations->expects(self::once())->method('refund')->with('order-1', 12.5, 'damaged', 'key-12345678', null)
            ->willReturn(PaymentOperationResult::done($this->payment('FULFILLED')));

        $this->mutations()->adminPaymentRefund(new ID('order-1'), 12.5, 'damaged', 'key-12345678');
    }

    public function testTheNewReasonAndDescriptionArguments_AreDelegated(): void
    {
        $this->operations->expects(self::once())->method('capture')->with('order-1', null, null, 'shipped')
            ->willReturn(PaymentOperationResult::done($this->payment('FULFILLED')));
        $this->operations->expects(self::once())->method('refund')->with('order-1', 5.0, null, null, 'desc')
            ->willReturn(PaymentOperationResult::done($this->payment('FULFILLED')));
        $this->operations->expects(self::once())->method('void')->with('order-1', null, 'why')
            ->willReturn(PaymentOperationResult::done($this->payment('CANCELLED')));

        $this->mutations()->adminPaymentCapture(new ID('order-1'), reason: 'shipped');
        $this->mutations()->adminPaymentRefund(new ID('order-1'), 5.0, description: 'desc');
        $this->mutations()->adminPaymentVoid(new ID('order-1'), reason: 'why');
    }

    public function testAdminPaymentVoid_Delegates(): void
    {
        $this->operations->expects(self::once())->method('void')->with('order-1', null, null)
            ->willReturn(PaymentOperationResult::done($this->payment('CANCELLED')));

        $payload = $this->mutations()->adminPaymentVoid(new ID('order-1'));

        self::assertSame('CANCELLED', $payload->paymentContract()?->state());
    }

    public function testARefusalBecomesAPayloadError_NotAnException(): void
    {
        $this->operations->method('refund')->willReturn(
            PaymentOperationResult::refused(PaymentRefusal::invalid('amount', 'exceeds_refundable', 'too much')),
        );

        $payload = $this->mutations()->adminPaymentRefund(new ID('order-1'), 999.0);

        self::assertNull($payload->paymentContract());
        self::assertSame('validation_failed', $payload->errors()[0]->errorCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operationsAndTheirRights(): iterable
    {
        yield 'capture' => ['adminPaymentCapture', PaymentRightsProvider::CAPTURE];
        yield 'refund' => ['adminPaymentRefund', PaymentRightsProvider::REFUND];
        yield 'void' => ['adminPaymentVoid', PaymentRightsProvider::CAPTURE];
    }

    #[DataProvider('operationsAndTheirRights')]
    public function testEveryOperationIsAMutationBehindItsOwnRight_AndNothingBroaderIsUsed(string $method, string $right): void
    {
        $reflection = new ReflectionMethod(PaymentMutations::class, $method);

        self::assertCount(1, $reflection->getAttributes(Mutation::class));
        $rights = $reflection->getAttributes(Right::class);
        self::assertCount(1, $rights);
        self::assertSame([$right], $rights[0]->getArguments());
    }

    private function mutations(): PaymentMutations
    {
        return new PaymentMutations($this->operations);
    }

    private function payment(string $state): PaymentContractDetails
    {
        return new PaymentContractDetails('c', 'paypal', strtolower($state), 10.0, 10.0, 0.0, 'EUR', []);
    }
}
