<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * A panel provider that also declares action options: the FakePanelProvider it wraps does the work.
 */
final class FakeOptionsPanelProvider implements PaymentPanelProviderInterface, AdminActionOptionsProviderInterface
{
    public function __construct(private readonly FakePanelProvider $inner, private readonly AdminActionOptions $options)
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

    public function actionOptions(PaymentContractInterface $contract): AdminActionOptions
    {
        return $this->options;
    }
}
