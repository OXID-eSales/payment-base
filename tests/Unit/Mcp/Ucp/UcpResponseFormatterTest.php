<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Ucp;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Mcp\Ucp\UcpResponseFormatter;
use PHPUnit\Framework\TestCase;

class UcpResponseFormatterTest extends TestCase
{
    private UcpResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new UcpResponseFormatter();
    }

    private function createContractMock(string $state = 'draft'): PaymentContractInterface
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [
                [
                    'articleId' => 'prod_1',
                    'quantity' => 1,
                    'grossPrice' => 29.99,
                ],
            ],
            'totalGross' => 29.99,
            'totalNet' => 25.20,
            'totalVat' => 4.79,
            'currency' => 'EUR',
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_1');
        $contract->method('getStateValue')->willReturn($state);
        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }

    /**
     * @dataProvider stateToUcpStatusProvider
     */
    public function testStateMapping(string $contractState, string $expectedUcpStatus): void
    {
        $contract = $this->createContractMock($contractState);
        $result = $this->formatter->formatCheckoutSession($contract);

        $this->assertSame($expectedUcpStatus, $result['status']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function stateToUcpStatusProvider(): array
    {
        return [
            'draft' => ['draft', 'incomplete'],
            'not_finished' => ['not_finished', 'incomplete'],
            'pending' => ['pending', 'incomplete'],
            'authorized' => ['authorized', 'ready_for_complete'],
            'ready_to_commit' => ['ready_to_commit', 'completed'],
            'committed' => ['committed', 'completed'],
            'fulfilled' => ['fulfilled', 'completed'],
            'cancelled' => ['cancelled', 'canceled'],
            'expired' => ['expired', 'canceled'],
            'failed' => ['failed', 'canceled'],
        ];
    }

    public function testFormatCheckoutSessionIncludesLineItems(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckoutSession($contract);

        $this->assertCount(1, $result['line_items']);
        $this->assertSame('li_1', $result['line_items'][0]['id']);
        $this->assertSame('prod_1', $result['line_items'][0]['product_id']);
    }

    public function testAmountsInMinorUnits(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckoutSession($contract);

        $this->assertSame(2999, $result['line_items'][0]['unit_price']); // 29.99 => 2999
        $this->assertSame(2520, $result['totals']['subtotal']); // 25.20 => 2520
        $this->assertSame(479, $result['totals']['tax']); // 4.79 => 479
        $this->assertSame(2999, $result['totals']['total']); // 29.99 => 2999
    }

    public function testCurrencyIsLowercased(): void
    {
        $contract = $this->createContractMock();
        $result = $this->formatter->formatCheckoutSession($contract);

        $this->assertSame('eur', $result['currency']);
    }

    public function testFormatError(): void
    {
        $result = $this->formatter->formatError('not_found', 'Resource missing', 'checkout_id');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('not_found', $result['error']['type']);
        $this->assertSame('Resource missing', $result['error']['message']);
        $this->assertSame('checkout_id', $result['error']['param']);
    }

    public function testFormatErrorWithoutParam(): void
    {
        $result = $this->formatter->formatError('server_error', 'Internal error');

        $this->assertArrayNotHasKey('param', $result['error']);
    }
}
