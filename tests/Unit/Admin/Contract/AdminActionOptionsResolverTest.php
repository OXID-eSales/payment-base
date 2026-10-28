<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Contract;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionOptionsResolver;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakePanelProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminActionOptionsResolverTest extends TestCase
{
    public function testOptionsFor_ProviderWithoutTheInterface_IsFreeText(): void
    {
        $resolver = new PanelActionOptionsResolver(new PaymentPanelRegistry([new FakePanelProvider('paypal')]), new NullLogger());

        self::assertEquals(AdminActionOptions::freeText(), $resolver->optionsFor(ContractFactory::fulfilled('o-1', 'paypal')));
    }

    public function testOptionsFor_NoPanelForTheProvider_IsFreeText(): void
    {
        $resolver = new PanelActionOptionsResolver(new PaymentPanelRegistry([]), new NullLogger());

        self::assertEquals(AdminActionOptions::freeText(), $resolver->optionsFor(ContractFactory::fulfilled('o-1', 'mollie')));
    }

    public function testOptionsFor_ProviderWithTheInterface_AnswersItsOptionsForTheContract(): void
    {
        $options = new AdminActionOptions(['duplicate'], [], true, 100);
        $resolver = new PanelActionOptionsResolver(new PaymentPanelRegistry([$this->provider('stripe', $options)]), new NullLogger());

        self::assertSame($options, $resolver->optionsFor(ContractFactory::fulfilled('o-1', 'stripe')));
    }

    public function testOptionsFor_ProviderThatThrows_IsFreeText_NotAnError(): void
    {
        $resolver = new PanelActionOptionsResolver(new PaymentPanelRegistry([$this->provider('stripe', null)]), new NullLogger());

        self::assertEquals(AdminActionOptions::freeText(), $resolver->optionsFor(ContractFactory::fulfilled('o-1', 'stripe')));
    }

    private function provider(string $name, ?AdminActionOptions $options): PaymentPanelProviderInterface
    {
        return new class ($name, $options) implements PaymentPanelProviderInterface, AdminActionOptionsProviderInterface {
            public function __construct(private readonly string $name, private readonly ?AdminActionOptions $options)
            {
            }

            public function getProviderName(): string
            {
                return $this->name;
            }

            public function supports(PaymentPanelContext $context): bool
            {
                return true;
            }

            public function build(PaymentPanelContext $context): PaymentPanelRenderable
            {
                return new PaymentPanelRenderable('x.twig', [], $this->name);
            }

            public function handleAction(string $action, array $request, PaymentPanelContext $context): void
            {
            }

            public function actionOptions(\OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract): AdminActionOptions
            {
                return $this->options ?? throw new \RuntimeException('provider down');
            }
        };
    }
}
