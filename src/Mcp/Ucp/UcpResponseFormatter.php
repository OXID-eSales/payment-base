<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Ucp;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;

class UcpResponseFormatter implements UcpResponseFormatterInterface
{
    public function formatCheckoutSession(PaymentContractInterface $contract): array
    {
        $snapshot = $contract->getBasketSnapshot();
        $currency = $snapshot->getCurrency();

        return [
            'id' => $contract->getId(),
            'status' => $this->mapContractStateToUcpStatus($contract->getStateValue()),
            'currency' => strtolower($currency),
            'line_items' => $this->formatLineItems($snapshot),
            'totals' => [
                'subtotal' => MinorUnitConverter::toMinorUnits($snapshot->getTotalNet(), $currency),
                'tax' => MinorUnitConverter::toMinorUnits($snapshot->getTotalVat(), $currency),
                'total' => MinorUnitConverter::toMinorUnits($snapshot->getTotalGross(), $currency),
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
        $currency = $snapshot->getCurrency();
        $lineItems = [];
        foreach ($snapshot->getItems() as $index => $item) {
            $unitPrice = (float) ($item['grossPrice'] ?? $item['price'] ?? 0.0);
            $quantity = (int) ($item['quantity'] ?? 1);
            $lineItems[] = [
                'id' => 'li_' . ($index + 1),
                'product_id' => $item['articleId'] ?? $item['id'] ?? '',
                'quantity' => $quantity,
                'unit_price' => MinorUnitConverter::toMinorUnits($unitPrice, $currency),
                'total' => MinorUnitConverter::toMinorUnits($unitPrice * $quantity, $currency),
            ];
        }
        return $lineItems;
    }
}
