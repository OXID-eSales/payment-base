<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

use OxidEsales\PaymentBase\Adapter\ShopMailSenderInterface;
use OxidEsales\PaymentBase\Adapter\ShopOwnerAddressInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;

/**
 * VR-23 T1 - tells the shop owner a payment is at risk, through the shop's mailer the order mails use. The
 * text names the order, the contract, the reason and the provider reference - ids the owner looks up in the
 * admin, never customer data.
 *
 * @since 1.8.0
 */
class AtRiskOwnerMailer implements AtRiskOwnerMailerInterface
{
    public function __construct(
        private readonly ShopMailSenderInterface $sender,
        private readonly ShopOwnerAddressInterface $owners,
    ) {
    }

    public function notify(PaymentContractInterface $contract, AtRiskReason $reason, string $reference): bool
    {
        $owner = $this->owners->forShop($contract->getShopId());
        if (!$owner->isReachable()) {
            return false;
        }

        $body = $this->bodyOf($contract, $reason, $reference);

        return $this->sender->send(new DeferredOrderMail(
            kind: DeferredOrderMail::KIND_OWNER,
            from: $owner->fromAddress,
            fromName: $owner->name,
            recipients: [[$owner->address, $owner->name]],
            replyTo: [],
            cc: [],
            bcc: [],
            subject: 'Payment at risk: order ' . (string) $contract->getOrderId(),
            body: $body,
            altBody: $body,
        ), $contract->getShopId());
    }

    private function bodyOf(PaymentContractInterface $contract, AtRiskReason $reason, string $reference): string
    {
        return implode("\n", [
            'A payment needs your decision. Nothing was cancelled or refunded automatically.',
            '',
            'Order: ' . (string) $contract->getOrderId(),
            'Contract: ' . (string) $contract->getId(),
            'Provider: ' . (string) $contract->getProvider(),
            'Reason: ' . $reason->value,
            'Provider reference: ' . ($reference === '' ? '-' : $reference),
            '',
            'Open the order in the admin: its payment panel shows the flag. Acknowledge it when you have decided.',
        ]);
    }
}
