<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderCancelled;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReaderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * An admin cancelled an order whose payment still holds money: the shop's storno does not return it, and this
 * module never moves money on its own. It only leaves a log line that names what the admin may do next
 * (void the authorisation, or refund the rest) in the Payment tab of the order.
 *
 * @since 1.5.0
 */
final class OrderCancelledSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PaymentContractDetailsReaderInterface $details,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [AdminOrderCancelled::class => 'onOrderCancelled'];
    }

    public function onOrderCancelled(AdminOrderCancelled $event): void
    {
        $payment = $this->details->findByOrderId($event->orderId);
        if ($payment === null) {
            return;
        }
        if ($payment->amountCapturable() > 0.0) {
            $this->logger->warning('Order cancelled while its payment is only authorised: void it in the Payment tab', [
                'orderId' => $event->orderId,
                'provider' => $payment->provider,
                'offer' => 'void',
                'authorised' => $payment->amountAuthorised,
            ]);
            return;
        }
        if ($payment->amountRefundable() > 0.0) {
            $this->logger->warning('Order cancelled while its captured payment is not refunded: refund it in the Payment tab', [
                'orderId' => $event->orderId,
                'provider' => $payment->provider,
                'offer' => 'refund',
                'refundable' => $payment->amountRefundable(),
            ]);
        }
    }
}
