<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\PaymentBase\Mail\DeferredOrderMail;

/**
 * STRP-176 — hands an order mail core rendered earlier to the shop's MTA.
 *
 * @since 1.5.0
 */
interface ShopMailSenderInterface
{
    /**
     * @return bool whether the shop's mailer accepted the mail
     */
    public function send(DeferredOrderMail $mail, int $shopId): bool;
}
