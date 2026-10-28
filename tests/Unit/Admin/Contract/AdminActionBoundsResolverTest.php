<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Contract;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelActionBoundsResolver;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakeBoundsPanelProvider;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakePanelProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class AdminActionBoundsResolverTest extends TestCase
{
    public function testBoundsFor_ProviderWithoutTheInterface_DeclaresNothing(): void
    {
        $resolver = new PanelActionBoundsResolver(new PaymentPanelRegistry([new FakePanelProvider('paypal')]), new NullLogger());

        self::assertNull($resolver->boundsFor(ContractFactory::fulfilled('o-1', 'paypal')));
    }

    public function testBoundsFor_IsLazy_AndAsksTheProviderOnlyOnce(): void
    {
        $provider = new FakeBoundsPanelProvider(new FakePanelProvider('mollie'), static fn (): AdminActionBounds => new AdminActionBounds(7.0, 3.0));
        $resolver = new PanelActionBoundsResolver(new PaymentPanelRegistry([$provider]), new NullLogger());

        $lazy = $resolver->boundsFor(ContractFactory::fulfilled('o-1', 'mollie'));

        self::assertSame(0, $provider->asked, 'a read that never asks for a bound costs no provider call');
        self::assertSame(7.0, $lazy?->get()?->capturable);
        self::assertSame(3.0, $lazy->get()?->refundable);
        self::assertSame(1, $provider->asked);
    }

    public function testBoundsFor_ProviderNoOpinion_IsNull(): void
    {
        $provider = new FakeBoundsPanelProvider(new FakePanelProvider('mollie'), static fn (): ?AdminActionBounds => null);
        $resolver = new PanelActionBoundsResolver(new PaymentPanelRegistry([$provider]), new NullLogger());

        self::assertNull($resolver->boundsFor(ContractFactory::fulfilled('o-1', 'mollie'))?->get());
    }

    public function testBoundsFor_ProviderThatCannotRead_FailsClosed(): void
    {
        $provider = new FakeBoundsPanelProvider(new FakePanelProvider('mollie'), static function (): never {
            throw new RuntimeException('mollie api down');
        });
        $resolver = new PanelActionBoundsResolver(new PaymentPanelRegistry([$provider]), new NullLogger());

        $bounds = $resolver->boundsFor(ContractFactory::fulfilled('o-1', 'mollie'))?->get();

        self::assertFalse($bounds?->available);
        self::assertSame([0.0, 0.0], [$bounds->capturable, $bounds->refundable]);
    }
}
