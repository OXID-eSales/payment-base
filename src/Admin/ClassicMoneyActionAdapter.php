<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use OxidEsales\PaymentBase\Payment\MoneyOperationRunnerInterface;

/**
 * The classic Payment tab's capture, refund and cancel forms, handed to the one money path. The provider's panel
 * never sees these actions: it only renders its body and handles the actions that move no money of the contract's.
 * What the path refuses, or denies, the tab shows as the provider's own refusals are shown.
 *
 * @since 1.7.0
 */
final class ClassicMoneyActionAdapter
{
    private const ACTIONS = ['capture', 'refund', 'cancel'];

    public function __construct(
        private readonly MoneyOperationRunnerInterface $runner,
        private readonly AdminChannelInterface $channel,
        private readonly AdminRefusalFeedbackInterface $feedback,
    ) {
    }

    public function handles(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /**
     * @param array<string, mixed> $request the tab's form fields
     */
    public function execute(string $action, array $request, string $orderId): void
    {
        try {
            $operation = $this->operationOf($action, $request, $orderId);
        } catch (InvalidArgumentException $unreadable) {
            $this->feedback->show(new AdminActionRefusedException('amount_invalid', $unreadable->getMessage()));

            return;
        }
        try {
            $result = $this->runner->run($operation, $this->channel);
        } catch (AdminAccessDeniedException $denied) {
            $this->feedback->show(new AdminActionRefusedException($denied->denialCode(), $denied->getMessage()));

            return;
        }
        $refusal = $result->refusal();
        if ($refusal !== null) {
            $this->feedback->show(new AdminActionRefusedException($refusal->code, $refusal->message));
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @throws InvalidArgumentException the amount field holds something that is not a number
     */
    private function operationOf(string $action, array $request, string $orderId): AdminPaymentOperation
    {
        $key = $this->text($request, 'idempotency_key');

        return match ($action) {
            'capture' => AdminPaymentOperation::capture($orderId, $this->amount($request, 'capture_amount', false), $key, $this->text($request, 'capture_reason')),
            'refund' => AdminPaymentOperation::refund($orderId, (float) $this->amount($request, 'refund_amount', true), $this->text($request, 'refund_reason'), $key, $this->text($request, 'refund_description')),
            default => AdminPaymentOperation::void($orderId, $key, $this->text($request, 'cancel_reason')),
        };
    }

    /**
     * @param array<string, mixed> $request
     */
    private function amount(array $request, string $field, bool $required): ?float
    {
        $text = str_replace(',', '.', trim((string) ($this->text($request, $field) ?? '')));
        if ($text === '' && !$required) {
            return null;
        }
        if (!is_numeric($text)) {
            throw new InvalidArgumentException('The amount must be a number');
        }

        return (float) $text;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function text(array $request, string $field): ?string
    {
        $value = $request[$field] ?? null;

        return is_string($value) ? $value : null;
    }
}
