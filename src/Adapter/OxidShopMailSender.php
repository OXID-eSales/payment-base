<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\Email;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;

/**
 * STRP-176 — sends a stored order mail through the shop's own Email, so the
 * MTA settings, inline images and the other modules in the Email chain apply
 * exactly as they would have at order creation. Only the content is not
 * rendered again: it is the copy core made then.
 *
 * @since 1.5.0
 */
class OxidShopMailSender implements ShopMailSenderInterface
{
    public function send(DeferredOrderMail $mail, int $shopId): bool
    {
        $email = $this->newEmail();
        $this->useSmtpOf($email, $shopId);

        $email->setFrom($mail->from, $mail->fromName);
        foreach ($mail->recipients as [$address, $name]) {
            $email->setRecipient($address, $name);
        }
        foreach ($mail->replyTo as [$address, $name]) {
            $email->setReplyTo($address, $name);
        }
        foreach ($mail->cc as [$address, $name]) {
            $email->addCC($address, $name);
        }
        foreach ($mail->bcc as [$address, $name]) {
            $email->addBCC($address, $name);
        }
        $email->setSubject($mail->subject);
        $email->setBody($mail->body);
        $email->setAltBody($mail->altBody);

        return (bool) $email->send();
    }

    /**
     * The mailer that rendered the mail took its SMTP settings from the
     * order's shop (setMailParams()); a fresh one starts with the active
     * shop's, and a webhook request may run in another one.
     */
    private function useSmtpOf(Email $email, int $shopId): void
    {
        $shop = $this->loadShop($shopId);
        if ($shop !== null) {
            $email->setSmtp($shop);
        }
    }

    protected function newEmail(): Email
    {
        /** @var Email $email */
        $email = oxNew(Email::class);

        return $email;
    }

    /**
     * Core's Email::getShop($langId, $shopId) always loads the active shop,
     * whatever id it is given; hence the load here.
     */
    protected function loadShop(int $shopId): ?Shop
    {
        /** @var Shop $shop */
        $shop = oxNew(Shop::class);

        return $shop->load((string) $shopId) ? $shop : null;
    }
}
