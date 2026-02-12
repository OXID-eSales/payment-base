<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

class UcpResponseFormatter implements UcpResponseFormatterInterface
{
    public function formatCheckoutSession(PaymentContractInterface $contract): array
    {
        $snapshot = $contract->getBasketSnapshot();

        return [
            'id' => $contract->getId(),
            'status' => $this->mapContractStateToUcpStatus($contract->getStateValue()),
            'currency' => strtolower($snapshot->getCurrency()),
            'line_items' => $this->formatLineItems($snapshot),
            'totals' => [
                'subtotal' => $this->toMinorUnits($snapshot->getTotalNet()),
                'tax' => $this->toMinorUnits($snapshot->getTotalVat()),
                'total' => $this->toMinorUnits($snapshot->getTotalGross()),
            ],
        ];
    }

    public function formatError(string $type, string $message, ?string $param = null): array
    {
        $error = ['type' => $type, 'message' => $message];
        if ($param !== null) {
            $error['param'] = $param;
        }
        return ['error' => $error];
    }

    /**
     * Contract state to UCP checkout status.
     * UCP: incomplete, requires_escalation, ready_for_complete, completed, canceled
     */
    private function mapContractStateToUcpStatus(string $contractState): string
    {
        return match ($contractState) {
            'draft', 'not_finished', 'pending' => 'incomplete',
            'authorized' => 'ready_for_complete',
            'ready_to_commit', 'committed', 'fulfilled' => 'completed',
            'cancelled', 'expired', 'failed' => 'canceled',
            default => 'incomplete',
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
                'product_id' => $item['articleId'] ?? $item['id'] ?? '',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit_price' => $this->toMinorUnits($item['grossPrice'] ?? $item['price'] ?? 0.0),
                'total' => $this->toMinorUnits(
                    ($item['grossPrice'] ?? $item['price'] ?? 0.0) * (int) ($item['quantity'] ?? 1)
                ),
            ];
        }
        return $lineItems;
    }

    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
