<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment\Support;

use Closure;
use OxidEsales\PaymentBase\Payment\CancelGatewayInterface;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\PaymentBase\Payment\CaptureGatewayInterface;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\PaymentBase\Payment\CaptureResult;
use OxidEsales\PaymentBase\Payment\RefundGatewayInterface;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;

/**
 * A provider's gateways as one test double: records every request and answers like a PSP that succeeds,
 * unless a closure says otherwise (it may throw).
 */
final class FakeGateway implements CaptureGatewayInterface, RefundGatewayInterface, CancelGatewayInterface
{
    /** @var list<CaptureRequest|RefundRequest|CancelRequest> */
    public array $requests = [];

    private int $sequence = 0;

    /**
     * @param Closure(CaptureRequest|RefundRequest|CancelRequest): void|null $beforeAnswer runs inside the call
     */
    public function __construct(
        private readonly string $provider = 'paypal',
        private readonly ?Closure $beforeAnswer = null,
        private readonly string $refundStatus = RefundResult::SUCCEEDED,
    ) {
    }

    public function providerName(): string
    {
        return $this->provider;
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        $this->seen($request);

        return new CaptureResult('cap-' . ++$this->sequence, $request->amount);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $this->seen($request);

        return new RefundResult('re-' . ++$this->sequence, $request->amount, $this->refundStatus);
    }

    public function cancel(CancelRequest $request): void
    {
        $this->seen($request);
    }

    private function seen(CaptureRequest|RefundRequest|CancelRequest $request): void
    {
        $this->requests[] = $request;
        if ($this->beforeAnswer !== null) {
            ($this->beforeAnswer)($request);
        }
    }
}
