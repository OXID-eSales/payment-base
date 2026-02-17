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

        $response = [
            'id' => $contract->getId(),
            'status' => $this->mapContractStateToAcpStatus($contract->getStateValue()),
            'currency' => strtolower($snapshot->getCurrency()),
            'line_items' => $this->formatLineItems($snapshot),
            'totals' => $this->formatTotals($snapshot),
            'payment_providers' => $this->paymentProviders,
        ];

        $checkoutUrl = $contract->getProviderRedirectUrl();
        if ($checkoutUrl !== null && $checkoutUrl !== '') {
            $response['checkout_url'] = $checkoutUrl;
        }

        return $response;
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
            $quantity = (int) ($item['quantity'] ?? 1);
            $grossPrice = (float) ($item['totalPrice'] ?? $item['grossPrice'] ?? 0.0);
            $netPrice = (float) ($item['netPrice'] ?? $grossPrice);
            $vatValue = (float) ($item['vatValue'] ?? 0.0);

            if ($grossPrice === 0.0 && isset($item['unitPrice'])) {
                $grossPrice = (float) $item['unitPrice'] * $quantity;
                $netPrice = $grossPrice;
            }

            $lineItems[] = [
                'id' => 'li_' . ($index + 1),
                'item' => [
                    'id' => $item['productId'] ?? $item['articleId'] ?? $item['id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'quantity' => $quantity,
                ],
                'base_amount' => $this->toMinorUnits($grossPrice),
                'subtotal' => $this->toMinorUnits($netPrice),
                'tax' => $this->toMinorUnits($vatValue),
                'total' => $this->toMinorUnits($grossPrice),
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
