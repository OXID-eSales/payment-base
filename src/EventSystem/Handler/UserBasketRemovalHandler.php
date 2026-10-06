<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Core's placeOrder deletes the user basket once the order exists so it
 * cannot be ordered twice (BeforeBasketRemoveOnPlaceOrder). For a headless
 * contract that moment is the commit: the `oxuserbaskets` row stamped on the
 * contract (`basket_id`, Sprint 15 / S3) goes then - never on cancel, so the
 * shopper can retry. A session contract carries no basket id and is left
 * alone. Best-effort: a basket that will not delete must not undo a commit.
 *
 * @since 3.0.0
 */
class UserBasketRemovalHandler implements HandlerInterface
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public static function getHandledEventClass(): string
    {
        return ContractCommittedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractCommittedEvent) {
            return;
        }

        $basketId = $event->getContract()->getMetadata('basket_id');
        if (!is_string($basketId) || $basketId === '') {
            return;
        }

        try {
            $deleted = $this->deleteUserBasket($basketId);
        } catch (Throwable $e) {
            $deleted = false;
            $this->logger->warning('[UserBasketRemovalHandler] could not delete the user basket', [
                'basketId' => $basketId,
                'contractId' => $event->getContractId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('[UserBasketRemovalHandler] user basket removed after commit', [
            'basketId' => $basketId,
            'contractId' => $event->getContractId(),
            'deleted' => $deleted,
        ]);
    }

    /**
     * Seam: delete the `oxuserbaskets` row (and its items).
     */
    protected function deleteUserBasket(string $basketId): bool
    {
        /** @var UserBasket $row */
        $row = oxNew(UserBasket::class);
        if (!$row->load($basketId)) {
            return false;
        }

        return (bool) $row->delete();
    }
}
