<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\AtRisk;

use OxidEsales\PaymentBase\Adapter\ShopMailSenderInterface;
use OxidEsales\PaymentBase\Adapter\ShopOwnerAddress;
use OxidEsales\PaymentBase\Adapter\ShopOwnerAddressInterface;
use OxidEsales\PaymentBase\AtRisk\AtRiskOwnerMailer;
use OxidEsales\PaymentBase\AtRisk\AtRiskReason;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use PHPUnit\Framework\TestCase;

/**
 * VR-23 T1 - the owner mail goes through the shop's own mailer (the one the order mails use): same sender,
 * the shop's SMTP settings, no second transport. It names ids and the reason, never customer data.
 */
final class AtRiskOwnerMailerTest extends TestCase
{
    public function testTheMailGoesToTheShopOwnerOfTheContractsShopThroughTheShopMailer(): void
    {
        $sent = [];
        $sender = $this->createMock(ShopMailSenderInterface::class);
        $sender->expects(self::once())->method('send')
            ->willReturnCallback(static function (DeferredOrderMail $mail, int $shopId) use (&$sent): bool {
                $sent = [$mail, $shopId];

                return true;
            });
        $owners = $this->createMock(ShopOwnerAddressInterface::class);
        $owners->method('forShop')->with(3)
            ->willReturn(new ShopOwnerAddress('owner@shop.example', 'Shop', 'shop@shop.example'));

        $delivered = (new AtRiskOwnerMailer($sender, $owners))->notify($this->contract(), AtRiskReason::DisputeOpened, 'dp_1');

        self::assertTrue($delivered);
        [$mail, $shopId] = $sent;
        self::assertSame(3, $shopId);
        self::assertSame(DeferredOrderMail::KIND_OWNER, $mail->kind);
        self::assertSame([['owner@shop.example', 'Shop']], $mail->recipients);
        self::assertSame('shop@shop.example', $mail->from);
        self::assertStringContainsString('order-7', $mail->subject);
        self::assertStringContainsString('dispute_opened', $mail->body);
        self::assertStringContainsString('dp_1', $mail->body);
        self::assertStringContainsString('contract-9', $mail->body);
        self::assertSame($mail->body, $mail->altBody);
    }

    public function testAShopWithoutOwnerAddressIsNotMailedAndSaysSo(): void
    {
        $sender = $this->createMock(ShopMailSenderInterface::class);
        $sender->expects(self::never())->method('send');
        $owners = $this->createMock(ShopOwnerAddressInterface::class);
        $owners->method('forShop')->willReturn(new ShopOwnerAddress('', 'Shop', ''));

        self::assertFalse((new AtRiskOwnerMailer($sender, $owners))->notify($this->contract(), AtRiskReason::CaptureDenied, ''));
    }

    private function contract(): PaymentContract
    {
        $contract = new PaymentContract(3, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6,
            'currency' => 'EUR',
        ]), 'contract-9');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-7');

        return $contract;
    }
}
