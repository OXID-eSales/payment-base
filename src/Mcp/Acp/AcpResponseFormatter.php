<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

class AcpResponseFormatter implements AcpResponseFormatterInterface
{
    /**
     * @param array<int, array<string, mixed>> $paymentProviders
     */
    public function __construct(
        private readonly array $paymentProviders = []
    ) {
    }

    public function formatCheckout(PaymentContractInterface $contract): array
    {
        $snapshot = $contract->getBasketSnapshot();

        return [
            'id' => $contract->getId(),
            'status' => $this->mapContractStateToAcpStatus($contract->getStateValue()),
            'currency' => strtolower($snapshot->getCurrency()),
            'line_items' => $this->formatLineItems($snapshot),
            'totals' => $this->formatTotals($snapshot),
            'payment_providers' => $this->paymentProviders,
        ];
    }

    public function formatOrder(PaymentContractInterface $contract, string $orderPermalink): array
    {
        return [
            'id' => $contract->getOrderId(),
            'checkout_session_id' => $contract->getId(),
            'permalink_url' => $orderPermalink,
        ];
    }

    public function notFoundError(string $checkoutId): array
    {
        return [
            'error' => [
                'type' => 'invalid_request',
                'message' => "Checkout not found: {$checkoutId}",
            ],
        ];
    }

    public function validationError(string $message, ?string $param = null): array
    {
        $error = [
            'type' => 'invalid_request',
            'message' => $message,
            'code' => 'invalid',
        ];
        if ($param !== null) {
            $error['param'] = $param;
        }
        return ['error' => $error];
    }

    /**
     * Contract state to ACP checkout status.
     *
     * ACP defines: not_ready_for_payment, ready_for_payment, completed, canceled
     * Note: ACP uses American spelling 'canceled' (one 'l').
     */
    private function mapContractStateToAcpStatus(string $contractState): string
    {
        return match ($contractState) {
            'draft', 'not_finished' => 'not_ready_for_payment',
            'pending', 'authorized' => 'ready_for_payment',
            'ready_to_commit', 'committed', 'fulfilled' => 'completed',
            'cancelled', 'expired', 'failed' => 'canceled',
            default => 'not_ready_for_payment',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatLineItems(BasketSnapshot $snapshot): array
    {
        $lineItems = [];
        foreach ($snapshot->getItems() as $index => $item) {
            $lineItems[] = [
                'id' => 'li_' . ($index + 1),
                'item' => [
                    'id' => $item['articleId'] ?? $item['id'] ?? '',
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ],
                'base_amount' => $this->toMinorUnits((float) ($item['grossPrice'] ?? $item['price'] ?? 0.0)),
                'subtotal' => $this->toMinorUnits((float) ($item['netPrice'] ?? $item['price'] ?? 0.0)),
                'tax' => $this->toMinorUnits((float) ($item['vatValue'] ?? 0.0)),
                'total' => $this->toMinorUnits((float) ($item['grossPrice'] ?? $item['price'] ?? 0.0)),
            ];
        }
        return $lineItems;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatTotals(BasketSnapshot $snapshot): array
    {
        return [
            ['type' => 'subtotal', 'amount' => $this->toMinorUnits($snapshot->getTotalNet())],
            ['type' => 'tax', 'amount' => $this->toMinorUnits($snapshot->getTotalVat())],
            ['type' => 'total', 'amount' => $this->toMinorUnits($snapshot->getTotalGross())],
        ];
    }

    /**
     * Convert float amount to integer minor units (cents).
     * ACP amounts are always integers in the smallest currency unit.
     */
    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
