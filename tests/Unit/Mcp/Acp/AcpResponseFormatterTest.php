<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Acp;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpResponseFormatter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AcpResponseFormatterTest extends TestCase
{
    private AcpResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new AcpResponseFormatter([
            ['provider' => 'stripe', 'supported_payment_methods' => ['card']],
        ]);
    }

    private function createContractMock(
        string $id = 'contract_1',
        string $state = 'draft',
        float $amount = 49.99,
        string $currency = 'EUR',
        ?string $orderId = null
    ): PaymentContractInterface {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [
                [
                    'articleId' => 'prod_1',
                    'quantity' => 2,
                    'grossPrice' => 24.99,
                    'netPrice' => 20.99,
                    'vatValue' => 4.00,
                ],
            ],
            'totalGross' => $amount,
            'totalNet' => 41.98,
            'totalVat' => 8.01,
            'currency' => $currency,
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getStateValue')->willReturn($state);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCurrency')->willReturn($currency);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }

    #[DataProvider('stateToAcpStatusProvider')]
    public function testStateMapping(string $contractState, string $expectedAcpStatus): void
    {
        $contract = $this->createContractMock(state: $contractState);

        $result = $this->formatter->formatCheckout($contract);

        $this->assertSame($expectedAcpStatus, $result['status']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function stateToAcpStatusProvider(): array
    {
        return [
            'draft' => ['draft', 'not_ready_for_payment'],
            'not_finished' => ['not_finished', 'not_ready_for_payment'],
            'pending' => ['pending', 'ready_for_payment'],
            'authorized' => ['authorized', 'ready_for_payment'],
            'ready_to_commit' => ['ready_to_commit', 'completed'],
            'committed' => ['committed', 'completed'],
            'fulfilled' => ['fulfilled', 'completed'],
            'cancelled' => ['cancelled', 'canceled'],
            'expired' => ['expired', 'canceled'],
            'failed' => ['failed', 'canceled'],
            'unknown' => ['unknown_state', 'not_ready_for_payment'],
        ];
    }

    public function testFormatCheckoutIncludesLineItems(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckout($contract);

        $this->assertCount(1, $result['line_items']);
        $this->assertSame('li_1', $result['line_items'][0]['id']);
        $this->assertSame('prod_1', $result['line_items'][0]['item']['id']);
        $this->assertSame(2, $result['line_items'][0]['item']['quantity']);
    }

    public function testAmountsConvertedToMinorUnits(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckout($contract);

        // grossPrice 24.99 => 2499 cents
        $this->assertSame(2499, $result['line_items'][0]['base_amount']);
        $this->assertSame(2499, $result['line_items'][0]['total']);
    }

    public function testTotalsAreInMinorUnits(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckout($contract);

        $totals = $result['totals'];
        $this->assertSame('subtotal', $totals[0]['type']);
        $this->assertSame(4198, $totals[0]['amount']); // 41.98 => 4198
        $this->assertSame('tax', $totals[1]['type']);
        $this->assertSame(801, $totals[1]['amount']); // 8.01 => 801
        $this->assertSame('total', $totals[2]['type']);
        $this->assertSame(4999, $totals[2]['amount']); // 49.99 => 4999
    }

    public function testCurrencyIsLowercased(): void
    {
        $contract = $this->createContractMock(currency: 'USD');
        $result = $this->formatter->formatCheckout($contract);

        $this->assertSame('usd', $result['currency']);
    }

    public function testPaymentProvidersIncluded(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckout($contract);

        $this->assertCount(1, $result['payment_providers']);
        $this->assertSame('stripe', $result['payment_providers'][0]['provider']);
    }

    public function testFormatOrder(): void
    {
        $contract = $this->createContractMock(id: 'c_1', orderId: 'ord_1');
        $result = $this->formatter->formatOrder($contract, 'https://shop.test/order/ord_1');

        $this->assertSame('ord_1', $result['id']);
        $this->assertSame('c_1', $result['checkout_session_id']);
        $this->assertSame('https://shop.test/order/ord_1', $result['permalink_url']);
    }

    public function testNotFoundError(): void
    {
        $result = $this->formatter->notFoundError('missing_id');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_request', $result['error']['type']);
        $this->assertStringContainsString('missing_id', $result['error']['message']);
    }

    public function testValidationError(): void
    {
        $result = $this->formatter->validationError('Bad input', 'field_name');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_request', $result['error']['type']);
        $this->assertSame('Bad input', $result['error']['message']);
        $this->assertSame('invalid', $result['error']['code']);
        $this->assertSame('field_name', $result['error']['param']);
    }

    public function testValidationErrorWithoutParam(): void
    {
        $result = $this->formatter->validationError('General error');

        $this->assertArrayNotHasKey('param', $result['error']);
    }
}
