<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use Closure;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;

/**
 * The provider boundary of the classic Payment tab (what the PayPal/Stripe/Mollie panel providers implement),
 * as a test double: records every action it is handed and runs a closure that stands for "the provider adapter
 * did its work" (it may change the contract, throw, or do nothing).
 */
final class FakePanelProvider implements PaymentPanelProviderInterface
{
    /** @var list<array{action: string, request: array<string, mixed>, orderId: string}> */
    public array $calls = [];

    /**
     * @param Closure(string, array<string, mixed>, PaymentPanelContext): void|null $effect
     */
    public function __construct(private readonly string $name = 'paypal', private readonly ?Closure $effect = null)
    {
    }

    public function getProviderName(): string
    {
        return $this->name;
    }

    public function supports(PaymentPanelContext $context): bool
    {
        return $context->getProviderName() === $this->name;
    }

    public function build(PaymentPanelContext $context): PaymentPanelRenderable
    {
        return new PaymentPanelRenderable('fake.html.twig', [], $this->name);
    }

    public function handleAction(string $action, array $request, PaymentPanelContext $context): void
    {
        $this->calls[] = ['action' => $action, 'request' => $request, 'orderId' => $context->orderId];
        if ($this->effect !== null) {
            ($this->effect)($action, $request, $context);
        }
    }
}
