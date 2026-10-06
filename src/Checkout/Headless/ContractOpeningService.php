<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScopeInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;

/**
 * Same chain as every other checkout, driven without a session: the basket
 * scope is entered first (so EarlyOrderCreationHandler's open-attempt
 * registry keys by basket), the S1 provider builds the shop basket from the
 * `oxuserbaskets` row, the contract is created and stamped (`basket_id`,
 * `channel`), ContractDraftCompletedEvent runs the handlers, the reloaded
 * contract is answered and the scope moves to it.
 *
 * @since 3.0.0
 */
final class ContractOpeningService implements ContractOpeningServiceInterface
{
    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly ContractRepositoryInterface $contracts,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CheckoutBasketProviderInterface $baskets,
        private readonly HeadlessCheckoutScopeInterface $scope,
    ) {
    }

    public function open(string $userId, string $basketId, string $paymentId, string $channel): PaymentContractInterface
    {
        $this->scope->enter($basketId, $userId, $basketId);

        try {
            $basket = $this->baskets->basketFor(new CreateOrderRequest(
                sessionId: 'headless:' . $basketId,
                userId: $userId,
                paymentId: $paymentId,
                basketId: $basketId,
            ));
        } catch (ShopOrderException $e) {
            throw new HeadlessCheckoutException($e->getErrorCode(), $e->getMessage());
        }

        if ($basket === null) {
            throw new HeadlessCheckoutException(
                HeadlessCheckoutException::BASKET_NOT_FOUND,
                sprintf('Basket %s not found', $basketId)
            );
        }

        $contract = $this->contractService->createContract($userId, $basket);
        $contract->setMetadata('basket_id', $basketId);
        $contract->setMetadata('channel', $channel);
        $this->contracts->save($contract);

        $this->dispatcher->dispatch(new ContractDraftCompletedEvent($contract, new EventContext([
            'paymentId' => $paymentId,
            'basketId' => $basketId,
            'sessionId' => 'headless:' . $basketId,
            'channel' => $channel,
            'headless' => true,
        ])));

        $opened = $this->contracts->findById((string) $contract->getId()) ?? $contract;
        $this->scope->enter((string) $opened->getId(), $userId, $basketId);

        return $opened;
    }
}
