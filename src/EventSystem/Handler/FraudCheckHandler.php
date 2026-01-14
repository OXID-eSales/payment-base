<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FraudScoringServiceInterface;

/**
 * Handles fraud checking on payment initiation.
 *
 * Calculates a risk score based on various fraud indicators:
 * - Address mismatch (billing vs shipping)
 * - Disposable email domains
 * - High order values
 * - IP address patterns
 *
 * Risk Score Actions:
 * - 0-49: Auto-approve (fulfill fraud_check condition)
 * - 50-79: Manual review required (mark contract for review)
 * - 80-100: Auto-reject (fail contract)
 *
 * @since 1.0.0
 */
class FraudCheckHandler implements HandlerInterface
{
    private const RISK_THRESHOLD_REJECT = 80;
    private const RISK_THRESHOLD_REVIEW = 50;

    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private FraudScoringServiceInterface $fraudScoring
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentInitiatedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentInitiatedEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->get('contract');

        if (!$contract) {
            return;
        }

        $riskScore = $this->fraudScoring->calculateRiskScore([
            'amount' => $event->getAmount(),
            'currency' => $event->getCurrency(),
            'billingAddress' => $context->get('billingAddress'),
            'shippingAddress' => $context->get('shippingAddress'),
            'email' => $context->get('email'),
            'ipAddress' => $context->get('ipAddress'),
        ]);

        if ($riskScore >= self::RISK_THRESHOLD_REJECT) {
            // High risk: Reject transaction
            $contract->fail('High fraud risk detected (score: ' . $riskScore . ')');
        } elseif ($riskScore >= self::RISK_THRESHOLD_REVIEW) {
            // Medium risk: Require manual review
            $context->set('requiresManualReview', true);
            $context->set('riskScore', $riskScore);
        } else {
            // Low risk: Auto-approve
            $contract->fulfillCondition(
                ContractCondition::TYPE_FRAUD_CHECK,
                [
                    'riskScore' => $riskScore,
                    'checkedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]
            );
        }

        $this->contractRepository->save($contract);
    }
}
