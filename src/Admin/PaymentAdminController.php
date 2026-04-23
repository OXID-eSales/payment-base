<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentComponent\Admin\Panel\UnsupportedPaymentActionException;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;

/**
 * The one and only admin controller for the shared "Payment" tab.
 *
 * Resolves the active payment-component contract for the edited order,
 * hands the resulting {@see PaymentPanelContext} to the
 * {@see PaymentPanelRegistryInterface}, and renders the wrapper template
 * around the provider's panel. When no registered PSP supports the order
 * (e.g. invoice / prepayment / cash-on-delivery) the wrapper renders a
 * friendly "not processed through a registered online payment provider"
 * notice instead.
 *
 * Memory guards (encoded here as hard rules):
 *
 * - Never override `getViewData()` — that is OXID's own method and the
 *   entire template context depends on it. Provider-specific data is
 *   exposed via `getPanelViewData()` / `getPanelRenderable()` accessors.
 * - No `oxNew()` for services (constructor DI only). `oxNew(Order)` is
 *   kept because the OXID admin frame supplies no alternative.
 * - The panel's template is loaded through OXID's Twig — the controller
 *   itself only drops the renderable into view data.
 */
class PaymentAdminController extends AdminDetailsController
{
    /**
     * @var string OXID core declares this untyped — must stay untyped here.
     */
    protected $_sThisTemplate = '@oe_payment_component/admin/payment_admin_tab';

    /** @var Order|null */
    protected ?Order $loadedOrder = null;

    protected ?PaymentContractInterface $loadedContract = null;

    protected ?PaymentPanelProviderInterface $resolvedProvider = null;

    protected ?PaymentPanelRenderable $renderable = null;

    protected ?PaymentPanelRegistryInterface $panelRegistry = null;

    protected ?ContractRepositoryInterface $contractRepository = null;

    protected ?PaymentAdminActionDispatcher $actionDispatcher = null;

    /**
     * Constructor accepts all three dependencies for test injection, but
     * OXID admin's ShopControl instantiates this class via oxNew (metadata.php
     * `controllers` path) without arguments — so we make everything optional
     * and lazy-fetch from the container when unset. See memory note
     * `feedback_oxid_controller_duplication.md`.
     */
    public function __construct(
        ?PaymentPanelRegistryInterface $panelRegistry = null,
        ?ContractRepositoryInterface $contractRepository = null,
        ?PaymentAdminActionDispatcher $actionDispatcher = null,
    ) {
        $this->panelRegistry = $panelRegistry;
        $this->contractRepository = $contractRepository;
        $this->actionDispatcher = $actionDispatcher;
        parent::__construct();
    }

    protected function panelRegistry(): PaymentPanelRegistryInterface
    {
        if ($this->panelRegistry !== null) {
            return $this->panelRegistry;
        }
        /** @phpstan-ignore-next-line container.notFound */
        return $this->panelRegistry = ContainerFactory::getInstance()->getContainer()
            ->get(PaymentPanelRegistryInterface::class);
    }

    protected function contractRepository(): ContractRepositoryInterface
    {
        if ($this->contractRepository !== null) {
            return $this->contractRepository;
        }
        /** @phpstan-ignore-next-line container.notFound */
        return $this->contractRepository = ContainerFactory::getInstance()->getContainer()
            ->get(ContractRepositoryInterface::class);
    }

    protected function actionDispatcher(): PaymentAdminActionDispatcher
    {
        if ($this->actionDispatcher !== null) {
            return $this->actionDispatcher;
        }
        /** @phpstan-ignore-next-line container.notFound */
        return $this->actionDispatcher = ContainerFactory::getInstance()->getContainer()
            ->get(PaymentAdminActionDispatcher::class);
    }

    public function render(): string
    {
        parent::render();

        $order = $this->getOrder();
        if ($order !== null) {
            $this->_aViewData['edit'] = $order;
        }

        $context = $this->buildPanelContext();
        if ($context !== null) {
            $this->resolvedProvider = $this->panelRegistry()->resolveFor($context);
            if ($this->resolvedProvider !== null) {
                $this->renderable = $this->resolvedProvider->build($context);
            }
        }

        return $this->_sThisTemplate;
    }

    public function getOrder(): ?Order
    {
        if ($this->loadedOrder instanceof Order) {
            return $this->loadedOrder;
        }
        $oxid = (string) $this->getEditObjectId();
        if ($oxid === '' || $oxid === '-1') {
            return null;
        }
        /** @phpstan-ignore-next-line function.notFound OXID core oxNew */
        $order = oxNew(Order::class);
        /** @var Order $order */
        if (!$order->load($oxid)) {
            return null;
        }
        return $this->loadedOrder = $order;
    }

    public function hasProvider(): bool
    {
        return $this->resolvedProvider !== null && $this->renderable !== null;
    }

    public function getProviderKey(): ?string
    {
        return $this->resolvedProvider?->getProviderName();
    }

    public function getPanelTemplatePath(): ?string
    {
        return $this->renderable?->templatePath;
    }

    /**
     * View-data bag for the active panel. Deliberately NOT named
     * `getViewData()` — see the class docblock.
     *
     * @return array<string, mixed>
     */
    public function getPanelViewData(): array
    {
        return $this->renderable !== null ? $this->renderable->viewData : [];
    }

    public function dispatchAction(): void
    {
        if (!$this->validateCsrfToken()) {
            return;
        }
        $action = $this->readStringParam('payment_admin_action');
        if ($action === null || $action === '') {
            return;
        }

        $context = $this->buildPanelContext();
        if ($context === null) {
            return;
        }

        try {
            $this->actionDispatcher()->dispatch($action, $this->collectActionRequest(), $context);
        } catch (UnsupportedPaymentActionException $e) {
            Registry::getLogger()->warning('[PaymentAdmin] action rejected', [
                'action' => $action,
                'orderId' => $context->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function buildPanelContext(): ?PaymentPanelContext
    {
        $order = $this->getOrder();
        if ($order === null) {
            return null;
        }
        $orderId = (string) $order->getId();
        if ($orderId === '') {
            return null;
        }
        $paymentType = (string) $order->getFieldData('oxpaymenttype');
        $contract = $this->loadContract($orderId);
        return new PaymentPanelContext(
            orderId: $orderId,
            paymentType: $paymentType,
            contract: $contract,
        );
    }

    protected function loadContract(string $orderId): ?PaymentContractInterface
    {
        if ($this->loadedContract !== null) {
            return $this->loadedContract;
        }
        return $this->loadedContract = $this->contractRepository()->findByOrderId($orderId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectActionRequest(): array
    {
        $request = Registry::getRequest();
        /** @var array<string, mixed> $body */
        $body = $_POST;
        $body['payment_admin_action'] = $request->getRequestEscapedParameter('payment_admin_action');
        return $body;
    }

    protected function readStringParam(string $name): ?string
    {
        $value = Registry::getRequest()->getRequestEscapedParameter($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function validateCsrfToken(): bool
    {
        $expected = Registry::getSession()->getSessionChallengeToken();
        $actual = (string) (Registry::getRequest()->getRequestEscapedParameter('stoken') ?? '');
        return $actual !== '' && hash_equals($expected, $actual);
    }
}
