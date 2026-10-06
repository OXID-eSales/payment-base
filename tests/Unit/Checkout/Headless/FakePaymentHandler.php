<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\PaymentBase\Adapter\ContractFirstPaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;

/**
 * A provider's OPC payment handler as the headless service sees it: declares
 * itself contract-first, answers for its payment ids, records the context it
 * was given, returns what the test scripted.
 */
class FakePaymentHandler implements ContractFirstPaymentHandlerInterface
{
    public ?PaymentContextInterface $processedWith = null;

    /**
     * @param list<string> $paymentIds
     */
    public function __construct(
        private readonly string $id,
        private readonly array $paymentIds,
        private ?PaymentHandlerResult $result = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return ucfirst($this->id);
    }

    public function supports(string $paymentMethodId): bool
    {
        return in_array($paymentMethodId, $this->paymentIds, true);
    }

    public function processPayment(PaymentContextInterface $context): PaymentHandlerResult
    {
        $this->processedWith = $context;

        return $this->result ?? PaymentHandlerResult::success(
            contractId: 'contract-1',
            clientSecret: null,
            metadata: ['redirectUrl' => 'https://psp.example/pay/cs_1', 'renderMode' => 'redirect', 'sessionId' => 'cs_1']
        );
    }

    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        return PaymentHandlerResult::success(contractId: $transactionId);
    }

    public function getFrontendConfig(): array
    {
        return [];
    }
}
