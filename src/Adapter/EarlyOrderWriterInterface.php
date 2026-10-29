<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * OPC-175 — the early order of a contract: created for a new contract, written
 * again for a contract that continues the open attempt (one order, one contract).
 *
 * @since OPC-175
 */
interface EarlyOrderWriterInterface
{
    public function write(PaymentContractInterface $contract, CreateOrderRequest $request): OrderResponse;
}
