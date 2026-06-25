<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 68a: H5 — State machine guard on fromArray().
 *
 * Tests that fromArray() rejects invalid states and detects
 * state/condition inconsistencies.
 */
#[CoversClass(\OxidEsales\PaymentBase\Contract\PaymentContract::class)]
#[CoversClass(\OxidEsales\PaymentBase\Contract\ContractState::class)]
#[Group('sprint-68a')]
#[Group('security')]
final class PaymentContractFromArrayGuardTest extends TestCase
{
    #[Test]
    public function fromArrayRejectsInvalidState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contract state');

        PaymentContract::fromArray($this->buildContractData('hacked'));
    }

    #[Test]
    public function fromArrayAcceptsAllValidStates(): void
    {
        $validStates = [
            'draft', 'not_finished', 'pending', 'authorized',
            'ready_to_commit', 'committed', 'fulfilled',
            'cancelled', 'expired', 'failed',
        ];

        foreach ($validStates as $state) {
            $contract = PaymentContract::fromArray($this->buildContractData($state));
            $this->assertSame($state, $contract->getStateValue(), "State '{$state}' should be accepted");
        }
    }

    #[Test]
    public function fromArrayRejectsEmptyState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty string');

        PaymentContract::fromArray($this->buildContractData(''));
    }

    #[Test]
    public function fromArrayRejectsNonStringState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('state must be a string');

        $data = $this->buildContractData('draft');
        unset($data['state']);

        PaymentContract::fromArray($data);
    }

    #[Test]
    public function fromArrayPreservesStateWithConditions(): void
    {
        $data = $this->buildContractData('fulfilled');
        $data['conditions'] = [
            ['type' => 'payment_authorized', 'status' => 'fulfilled', 'fulfilledAt' => date('Y-m-d H:i:s')],
        ];

        $contract = PaymentContract::fromArray($data);

        $this->assertSame('fulfilled', $contract->getStateValue());
    }

    #[Test]
    public function fromArrayDetectsInconsistentStateConditions(): void
    {
        $data = $this->buildContractData('fulfilled');
        $data['conditions'] = [
            ['type' => 'payment_authorized', 'status' => 'pending'],
        ];

        // E_USER_WARNING should be triggered for inconsistent state/conditions
        $warningTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$warningTriggered): bool {
            if ($errno === E_USER_WARNING && str_contains($errstr, 'state/condition inconsistency')) {
                $warningTriggered = true;
                return true;
            }
            return false;
        });

        try {
            $contract = PaymentContract::fromArray($data);
            $this->assertTrue($warningTriggered, 'E_USER_WARNING should be triggered for inconsistent state');
            $this->assertSame('fulfilled', $contract->getStateValue());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContractData(string $state): array
    {
        return [
            'id' => 'test_contract_' . bin2hex(random_bytes(4)),
            'shopId' => 1,
            'userId' => 'user_123',
            'state' => $state,
            'basketSnapshot' => [
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.03,
                'totalVat' => 15.97,
                'currency' => 'EUR',
                'capturedAt' => date('Y-m-d H:i:s'),
            ],
            'conditions' => [],
            'metadata' => [],
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }
}
