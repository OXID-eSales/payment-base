<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\PaymentCandidate;
use OxidEsales\PaymentBase\Checkout\PaymentCandidateFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fake standing in for OXID's Payment model. The factory only ever asks a
 * payment whether it collects data on the payment step, which core answers
 * through getDynValues() (parsed from oxpayments.OXVALDESC).
 */
final class FakePaymentModel
{
    /** @param array<int|string, mixed>|null $dynValues */
    public function __construct(
        private readonly ?array $dynValues = [],
        private readonly ?string $id = null,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /** @return array<int|string, mixed>|null */
    public function getDynValues(): ?array
    {
        return $this->dynValues;
    }
}

/**
 * A payment-like object that cannot answer the question at all — a foreign
 * module's model, or a broken row. Must degrade to "no input needed", never
 * throw into the checkout.
 */
final class MutePaymentModel
{
    public function getDynValues(): never
    {
        throw new RuntimeException('no such field');
    }
}

#[CoversClass(PaymentCandidateFactory::class)]
final class PaymentCandidateFactoryTest extends TestCase
{
    public function testMapsKeysToCandidateIds(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList([
            'oxidinvoice' => new FakePaymentModel(),
            'oxidcashondel' => new FakePaymentModel(),
        ]);

        $this->assertCount(2, $candidates);
        $this->assertContainsOnlyInstancesOf(PaymentCandidate::class, $candidates);
        $this->assertSame(['oxidinvoice', 'oxidcashondel'], array_map(
            static fn (PaymentCandidate $c): string => $c->getId(),
            $candidates
        ));
    }

    public function testPaymentWithoutDynamicFieldsNeedsNoInput(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList(['oxidinvoice' => new FakePaymentModel([])]);

        $this->assertFalse($candidates[0]->requiresUserInput());
    }

    public function testPaymentWithDynamicFieldsRequiresInput(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList([
            'oxiddebitnote' => new FakePaymentModel(['lsbankname' => '', 'lsktonr' => '']),
        ]);

        $this->assertTrue($candidates[0]->requiresUserInput());
    }

    public function testNullDynamicValuesNeedNoInput(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList(['oxidinvoice' => new FakePaymentModel(null)]);

        $this->assertFalse($candidates[0]->requiresUserInput());
    }

    public function testUnanswerablePaymentDegradesToNoInput(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList(['weird' => new MutePaymentModel()]);

        $this->assertCount(1, $candidates);
        $this->assertFalse($candidates[0]->requiresUserInput());
    }

    public function testEmptyListMapsToEmptyCandidates(): void
    {
        $this->assertSame([], PaymentCandidateFactory::fromPaymentList([]));
    }

    /**
     * Core keys its payment list by payment id, so the key is the primary
     * source. When it is not usable (a numerically indexed array), the model's
     * own id still identifies the method — a candidate with the id "0" would be
     * a silent lie.
     */
    public function testNumericKeyFallsBackToTheModelId(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList([new FakePaymentModel([], 'oxidinvoice')]);

        $this->assertCount(1, $candidates);
        $this->assertSame('oxidinvoice', $candidates[0]->getId());
    }

    public function testEntryWithNeitherKeyNorModelIdIsDropped(): void
    {
        $this->assertSame([], PaymentCandidateFactory::fromPaymentList([new FakePaymentModel()]));
    }

    public function testStringKeyWinsOverAnythingElse(): void
    {
        $candidates = PaymentCandidateFactory::fromPaymentList(['oxidinvoice' => new FakePaymentModel([], 'other')]);

        $this->assertSame('oxidinvoice', $candidates[0]->getId());
    }
}
