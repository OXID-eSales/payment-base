<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Context;

use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;
use OxidEsales\PaymentBase\Checkout\Context\SessionCheckoutContext;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\RecordingSessionAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S2 — the Twig / OPC checkout's context IS the shop session.
 * Same keys, same values, so every flag a checkout left in the session before
 * this sprint is still found there, byte-identically.
 */
final class SessionCheckoutContextTest extends TestCase
{
    public function testImplementsTheContextContract(): void
    {
        self::assertInstanceOf(CheckoutContextInterface::class, new SessionCheckoutContext(new RecordingSessionAdapter()));
    }

    public function testTheScopeIsTheSessionId(): void
    {
        self::assertSame('session-1', (new SessionCheckoutContext(new RecordingSessionAdapter()))->getScopeId());
    }

    public function testReadsAndWritesSessionVariablesUnderTheSameKeys(): void
    {
        $session = new RecordingSessionAdapter();
        $context = new SessionCheckoutContext($session);

        $context->set('oepb_open_checkout_contract_id', 'contract-1');

        self::assertSame('contract-1', $session->getVariable('oepb_open_checkout_contract_id'));
        self::assertSame('contract-1', $context->get('oepb_open_checkout_contract_id'));
    }

    public function testAMissingKeyAnswersTheDefault(): void
    {
        $context = new SessionCheckoutContext(new RecordingSessionAdapter());

        self::assertNull($context->get('nothing'));
        self::assertSame('fallback', $context->get('nothing', 'fallback'));
    }

    /**
     * SessionAdapterInterface has no delete; the registry always wrote null to
     * forget. Removing keeps that contract so existing readers see "unset".
     */
    public function testRemovingLeavesTheKeyUnset(): void
    {
        $session = new RecordingSessionAdapter();
        $context = new SessionCheckoutContext($session);
        $context->set('flag', true);

        $context->remove('flag');

        self::assertNull($session->getVariable('flag'));
        self::assertNull($context->get('flag'));
    }
}
