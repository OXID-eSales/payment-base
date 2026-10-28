<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use Closure;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * A panel provider that also declares live bounds (Mollie's multi-capture, RefundBound): the FakePanelProvider it
 * wraps does the work, the closure answers (or throws) for the bounds. Counts the questions.
 */
final class FakeBoundsPanelProvider implements PaymentPanelProviderInterface, AdminActionBoundsProviderInterface
{
    public int $asked = 0;

    /**
     * @param Closure(PaymentContractInterface): ?AdminActionBounds $answer
     */
    public function __construct(private readonly FakePanelProvider $inner, private readonly Closure $answer)
    {
    }

    public function getProviderName(): string
    {
        return $this->inner->getProviderName();
    }

    public function supports(PaymentPanelContext $context): bool
    {
        return $this->inner->supports($context);
    }

    public function build(PaymentPanelContext $context): PaymentPanelRenderable
    {
        return $this->inner->build($context);
    }

    public function handleAction(string $action, array $request, PaymentPanelContext $context): void
    {
        $this->inner->handleAction($action, $request, $context);
    }

    public function boundsFor(PaymentContractInterface $contract): ?AdminActionBounds
    {
        ++$this->asked;

        return ($this->answer)($contract);
    }
}
