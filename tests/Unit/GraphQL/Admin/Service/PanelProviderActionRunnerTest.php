<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelProviderActionRunner;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\ProviderActionRunnerInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakePanelProvider;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class PanelProviderActionRunnerTest extends TestCase
{
    private InMemoryContracts $contracts;

    protected function setUp(): void
    {
        $this->contracts = new InMemoryContracts();
        $this->contracts->save(ContractFactory::authorized('order-1', 'paypal'));
    }

    public function testRun_HandsTheActionAndItsRequestToTheProviderOfTheContract(): void
    {
        $paypal = new FakePanelProvider('paypal');
        $stripe = new FakePanelProvider('stripe');

        $outcome = $this->runner([$stripe, $paypal])->run('paypal', 'order-1', 'capture', ['capture_amount' => 40.0]);

        self::assertSame(ProviderActionRunnerInterface::RAN, $outcome->status);
        self::assertSame([], $stripe->calls);
        self::assertSame(
            [['action' => 'capture', 'request' => ['capture_amount' => 40.0], 'orderId' => 'order-1']],
            $paypal->calls,
        );
    }

    public function testRun_NoProviderForTheContract_IsUnavailable_AndNothingRuns(): void
    {
        $stripe = new FakePanelProvider('stripe');

        $outcome = $this->runner([$stripe])->run('paypal', 'order-1', 'capture', []);

        self::assertSame(ProviderActionRunnerInterface::UNAVAILABLE, $outcome->status);
        self::assertSame([], $stripe->calls);
    }

    public function testRun_OrderWithoutAContract_IsUnavailable(): void
    {
        $paypal = new FakePanelProvider('paypal');

        self::assertSame(
            ProviderActionRunnerInterface::UNAVAILABLE,
            $this->runner([$paypal])->run('paypal', 'order-unknown', 'capture', [])->status,
        );
        self::assertSame([], $paypal->calls);
    }

    public function testRun_ProviderThrows_IsFailed_AndTheMessageGoesToTheLogOnly(): void
    {
        $paypal = new FakePanelProvider('paypal', static function (): void {
            throw new \RuntimeException('401 from the provider API');
        });
        $log = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $outcome = $this->runner([$paypal], $log)->run('paypal', 'order-1', 'refund', []);

        self::assertSame(ProviderActionRunnerInterface::FAILED, $outcome->status);
        self::assertCount(1, $log->records);
        self::assertSame('error', $log->records[0]['level']);
        self::assertSame('order-1', $log->records[0]['context']['orderId']);
        self::assertSame('refund', $log->records[0]['context']['action']);
    }

    public function testRun_ProviderRefuses_IsRefused_WithItsCodeAndSentence(): void
    {
        $paypal = new FakePanelProvider('paypal', static function (): void {
            throw new AdminActionRefusedException('amount_below_minimum', 'The amount is below the provider minimum.');
        });

        $outcome = $this->runner([$paypal])->run('paypal', 'order-1', 'refund', []);

        self::assertSame(ProviderActionRunnerInterface::REFUSED, $outcome->status);
        self::assertSame('amount_below_minimum', $outcome->refusalCode);
        self::assertSame('The amount is below the provider minimum.', $outcome->refusalMessage);
    }

    public function testRun_ARefusalIsNotAnError_ItIsLoggedAsInfoWithoutTheSentence(): void
    {
        $paypal = new FakePanelProvider('paypal', static function (): void {
            throw new AdminActionRefusedException('amount_below_minimum', 'secret-ish sentence');
        });
        $log = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $this->runner([$paypal], $log)->run('paypal', 'order-1', 'refund', []);

        self::assertSame('info', $log->records[0]['level']);
        self::assertSame('amount_below_minimum', $log->records[0]['context']['refusalCode']);
    }

    /**
     * @param list<FakePanelProvider> $providers
     */
    private function runner(array $providers, ?AbstractLogger $log = null): PanelProviderActionRunner
    {
        return new PanelProviderActionRunner(
            new PaymentPanelRegistry($providers),
            $this->contracts,
            $log ?? new \Psr\Log\NullLogger(),
        );
    }
}
