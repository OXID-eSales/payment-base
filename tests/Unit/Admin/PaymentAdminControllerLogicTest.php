<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Admin;

use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentComponent\Admin\PaymentAdminActionDispatcher;
use OxidEsales\PaymentComponent\Admin\PaymentAdminController;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint I — controller logic under test, stubbing OXID's admin bootstrap.
 *
 * The real {@see PaymentAdminController} extends OXID's AdminDetailsController,
 * which cannot be instantiated without the shop bootstrap. We replace the
 * OXID-integrated seams (`getEditObjectId`, `getOrder`) with a testable
 * subclass so the logic under test is the provider resolution and panel-data
 * hand-off — the contract this sprint introduces.
 */
final class PaymentAdminControllerLogicTest extends TestCase
{
    public function testRendersNoProviderStateWhenNoneMatches(): void
    {
        $controller = $this->controller(
            orderId: 'order_invoice',
            paymentType: 'oxidinvoice',
            contract: null,
            registry: $this->registryReturning(null),
        );

        $controller->render();

        self::assertFalse($controller->hasProvider());
        self::assertNull($controller->getProviderKey());
        self::assertNull($controller->getPanelTemplatePath());
        self::assertSame([], $controller->getPanelViewData());
    }

    public function testExposesPanelRenderableWhenProviderResolves(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn('stripe');

        $provider = $this->createMock(PaymentPanelProviderInterface::class);
        $provider->method('getProviderName')->willReturn('stripe');
        $provider->method('supports')->willReturn(true);
        $provider->expects(self::once())->method('build')->willReturn(
            new PaymentPanelRenderable(
                templatePath: '@oe_payments_stripe_wallet/admin/panel/stripe',
                viewData: ['contractId' => 'c_1', 'amount' => 42.0],
                providerKey: 'stripe',
            )
        );

        $controller = $this->controller(
            orderId: 'order_stripe',
            paymentType: 'oe_payments_stripe_wallet',
            contract: $contract,
            registry: $this->registryReturning($provider),
        );

        $controller->render();

        self::assertTrue($controller->hasProvider());
        self::assertSame('stripe', $controller->getProviderKey());
        self::assertSame('@oe_payments_stripe_wallet/admin/panel/stripe', $controller->getPanelTemplatePath());
        self::assertSame(['contractId' => 'c_1', 'amount' => 42.0], $controller->getPanelViewData());
    }

    public function testReturnsNoProviderWhenOrderIsMissing(): void
    {
        $controller = $this->controller(
            orderId: null,
            paymentType: '',
            contract: null,
            registry: $this->registryReturning($this->createMock(PaymentPanelProviderInterface::class)),
        );

        $controller->render();

        self::assertFalse($controller->hasProvider());
    }

    private function controller(
        ?string $orderId,
        string $paymentType,
        ?PaymentContractInterface $contract,
        PaymentPanelRegistryInterface $registry,
    ): TestablePaymentAdminController {
        $repo = $this->createMock(ContractRepositoryInterface::class);
        $repo->method('findByOrderId')->willReturn($contract);

        return new TestablePaymentAdminController(
            $registry,
            $repo,
            new PaymentAdminActionDispatcher($registry),
            $orderId,
            $paymentType,
        );
    }

    private function registryReturning(?PaymentPanelProviderInterface $provider): PaymentPanelRegistryInterface
    {
        $mock = $this->createMock(PaymentPanelRegistryInterface::class);
        $mock->method('resolveFor')->willReturn($provider);
        return $mock;
    }
}

/**
 * Testable subclass that bypasses OXID admin bootstrap and returns a fixed
 * order id / payment type. Mirrors the established pattern in Stripe's /
 * PayPal's existing admin tests (per the CLAUDE.md memory note).
 */
final class TestablePaymentAdminController extends PaymentAdminController
{
    public function __construct(
        PaymentPanelRegistryInterface $registry,
        ContractRepositoryInterface $repo,
        PaymentAdminActionDispatcher $dispatcher,
        private readonly ?string $stubOrderId,
        private readonly string $stubPaymentType,
    ) {
        $this->panelRegistry = $registry;
        $this->contractRepository = $repo;
        $this->actionDispatcher = $dispatcher;
    }

    public function render(): string
    {
        $context = $this->buildPanelContext();
        if ($context !== null) {
            $this->resolvedProvider = $this->panelRegistry->resolveFor($context);
            if ($this->resolvedProvider !== null) {
                $this->renderable = $this->resolvedProvider->build($context);
            }
        }
        return (string) $this->_sThisTemplate;
    }

    protected function buildPanelContext(): ?\OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext
    {
        if ($this->stubOrderId === null) {
            return null;
        }
        $contract = $this->contractRepository->findByOrderId($this->stubOrderId);
        return new PaymentPanelContext(
            orderId: $this->stubOrderId,
            paymentType: $this->stubPaymentType,
            contract: $contract,
        );
    }
}
