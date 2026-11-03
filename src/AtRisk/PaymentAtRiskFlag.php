<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @inheritDoc
 *
 * A mail problem never undoes or blocks the flag: the flag is the record, the mail is the nudge. A mail that
 * fails gives its claim back, so the next raise of the same flag (a provider redelivery) tries again.
 *
 * @since 1.8.0
 */
class PaymentAtRiskFlag implements PaymentAtRiskFlagInterface
{
    public function __construct(
        private readonly PaymentAtRiskRepositoryInterface $flags,
        private readonly AtRiskOwnerMailerInterface $ownerMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function raise(PaymentContractInterface $contract, AtRiskReason $reason, string $reference = ''): bool
    {
        $contractId = (string) $contract->getId();
        $isNew = $this->flags->insertIfAbsent(new PaymentAtRisk(
            bin2hex(random_bytes(16)),
            $contractId,
            $contract->getOrderId(),
            $reason,
            $reference,
            new DateTimeImmutable(),
        ));
        if ($isNew) {
            $this->logger->warning('[PaymentAtRiskFlag] payment flagged at risk', [
                'contractId' => $contractId,
                'orderId' => $contract->getOrderId(),
                'reason' => $reason->value,
                'reference' => $reference,
            ]);
        }

        $this->mailOwnerOnce($contract, $reason, $reference);

        return $isNew;
    }

    public function acknowledge(string $contractId, AtRiskReason $reason, string $reference = ''): bool
    {
        return $this->flags->acknowledge($contractId, $reason, $reference);
    }

    private function mailOwnerOnce(PaymentContractInterface $contract, AtRiskReason $reason, string $reference): void
    {
        $contractId = (string) $contract->getId();
        if (!$this->flags->claimOwnerMail($contractId, $reason, $reference)) {
            return;
        }

        try {
            if ($this->ownerMailer->notify($contract, $reason, $reference)) {
                return;
            }
            $this->logger->error('[PaymentAtRiskFlag] the shop mailer refused the owner mail', [
                'contractId' => $contractId,
                'reason' => $reason->value,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('[PaymentAtRiskFlag] the owner mail was not sent', [
                'contractId' => $contractId,
                'reason' => $reason->value,
                'exception' => $e::class,
            ]);
        }

        $this->flags->releaseOwnerMail($contractId, $reason, $reference);
    }
}
