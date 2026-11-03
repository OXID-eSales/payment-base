<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Return;

use OxidEsales\PaymentBase\Checkout\Return\ContractReturnTokenService;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessGuard;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessRejection;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessRequest;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessSettingsInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * VR-20 R3 - the one check in front of every return leg. PayPal's return leg took any
 * contract id from the URL (SEC-1); Stripe's let an unknown owner through; tokens never
 * expired. Valid token, or the paying shopper in the paying session - nothing else.
 */
final class ReturnAccessGuardTest extends TestCase
{
    private const NOW = 5000;
    private const TTL = 86400;

    private InMemoryReturnTokenSecretStore $secrets;

    protected function setUp(): void
    {
        $this->secrets = new InMemoryReturnTokenSecretStore('guard-secret');
    }

    public function testAValidTokenOpensTheReturnLegWithoutASession(): void
    {
        $token = $this->tokenIssuedAt(self::NOW, 'contract-1', 'user-1');

        $decision = $this->guard()->check($this->request(token: $token, sessionUserId: '', sessionId: ''));

        self::assertTrue($decision->granted);
        self::assertNull($decision->rejection);
    }

    public function testAnExpiredTokenIsRejectedAsExpired(): void
    {
        $token = $this->tokenIssuedAt(self::NOW - self::TTL - 1, 'contract-1', 'user-1');

        $decision = $this->guard()->check($this->request(token: $token, sessionUserId: '', sessionId: ''));

        self::assertFalse($decision->granted);
        self::assertSame(ReturnAccessRejection::TokenExpired, $decision->rejection);
    }

    public function testATokenOfAnotherContractIsRejected(): void
    {
        $token = $this->tokenIssuedAt(self::NOW, 'contract-2', 'user-1');

        $decision = $this->guard()->check($this->request(token: $token, sessionUserId: '', sessionId: ''));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testATokenSignedWithAForeignSecretIsRejected(): void
    {
        $foreign = new InMemoryReturnTokenSecretStore('someone-elses-secret');
        $token = $this->tokenService($foreign, self::NOW)->generateToken('contract-1', 'user-1');

        $decision = $this->guard()->check($this->request(token: $token, sessionUserId: '', sessionId: ''));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testTheContractsOwnerInTheInitiatingSessionNeedsNoToken(): void
    {
        $decision = $this->guard()->check($this->request());

        self::assertTrue($decision->granted);
    }

    public function testAnotherShopperInTheInitiatingSessionIsRejected(): void
    {
        $decision = $this->guard()->check($this->request(sessionUserId: 'user-2'));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testTheOwnerInAnotherSessionIsRejected(): void
    {
        $decision = $this->guard()->check($this->request(sessionId: 'session-other'));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testAnUnknownShopperIsRejected(): void
    {
        $decision = $this->guard()->check($this->request(sessionUserId: ''));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testAContractWithoutAnOwnerIsRejected(): void
    {
        $contract = $this->contract(userId: '');

        $decision = $this->guard()->check($this->request(contract: $contract, sessionUserId: ''));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testAContractWithoutAnInitiatingSessionIsNotOpenedByUserAlone(): void
    {
        $contract = $this->contract(initiatingSessionId: null);

        $decision = $this->guard()->check($this->request(contract: $contract));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testAnEmptySessionIdNeverMatchesAnEmptyInitiatingSession(): void
    {
        $contract = $this->contract(initiatingSessionId: '');

        $decision = $this->guard()->check($this->request(contract: $contract, sessionId: ''));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testAMissingContractIsRejected(): void
    {
        $decision = $this->guard()->check(new ReturnAccessRequest(null, 'testpsp', '', 'user-1', 'session-1'));

        self::assertSame(ReturnAccessRejection::ContractNotFound, $decision->rejection);
    }

    public function testAnotherProvidersContractIsRejectedEvenWithAValidToken(): void
    {
        $token = $this->tokenIssuedAt(self::NOW, 'contract-1', 'user-1');

        $decision = $this->guard()->check($this->request(token: $token, provider: 'other'));

        self::assertSame(ReturnAccessRejection::ProviderMismatch, $decision->rejection);
    }

    public function testAContractWithoutAProviderIsRejected(): void
    {
        $decision = $this->guard()->check($this->request(contract: $this->contract(provider: null)));

        self::assertSame(ReturnAccessRejection::ProviderMismatch, $decision->rejection);
    }

    public function testALegacyTokenIsAcceptedOnlyWhenTheSwitchIsOnAndTheProviderPassedItsValidator(): void
    {
        $legacy = $this->createMock(TokenServiceInterface::class);
        $legacy->method('validateToken')->with('legacy-token', 'contract-1')->willReturn(true);

        $granted = $this->guard(acceptLegacy: true)->check($this->request(
            token: 'legacy-token',
            sessionUserId: '',
            sessionId: '',
            legacyTokens: $legacy,
        ));

        self::assertTrue($granted->granted);
    }

    public function testALegacyTokenIsRejectedWhenTheSwitchIsOff(): void
    {
        $legacy = $this->createMock(TokenServiceInterface::class);
        $legacy->expects(self::never())->method('validateToken');

        $decision = $this->guard(acceptLegacy: false)->check($this->request(
            token: 'legacy-token',
            sessionUserId: '',
            sessionId: '',
            legacyTokens: $legacy,
        ));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testALegacyTokenIsRejectedWhenTheProviderPassedNoValidator(): void
    {
        $decision = $this->guard(acceptLegacy: true)->check($this->request(
            token: 'legacy-token',
            sessionUserId: '',
            sessionId: '',
        ));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testAnInvalidLegacyTokenIsRejected(): void
    {
        $legacy = $this->createStub(TokenServiceInterface::class);
        $legacy->method('validateToken')->willReturn(false);

        $decision = $this->guard(acceptLegacy: true)->check($this->request(
            token: 'legacy-token',
            sessionUserId: '',
            sessionId: '',
            legacyTokens: $legacy,
        ));

        self::assertSame(ReturnAccessRejection::AccessDenied, $decision->rejection);
    }

    public function testALegacyValidatorIsNeverAskedAboutATokenInTheNewFormat(): void
    {
        $legacy = $this->createMock(TokenServiceInterface::class);
        $legacy->expects(self::never())->method('validateToken');
        $foreign = $this->tokenService(new InMemoryReturnTokenSecretStore('x'), self::NOW)
            ->generateToken('contract-1', 'user-1');

        $decision = $this->guard(acceptLegacy: true)->check($this->request(
            token: $foreign,
            sessionUserId: '',
            sessionId: '',
            legacyTokens: $legacy,
        ));

        self::assertFalse($decision->granted);
    }

    private function guard(bool $acceptLegacy = false): ReturnAccessGuard
    {
        $settings = $this->settings($acceptLegacy);

        return new ReturnAccessGuard($this->tokenService($this->secrets, self::NOW), $settings);
    }

    private function settings(bool $acceptLegacy): ReturnAccessSettingsInterface
    {
        $settings = $this->createStub(ReturnAccessSettingsInterface::class);
        $settings->method('tokenTtlSeconds')->willReturn(self::TTL);
        $settings->method('acceptsLegacyTokens')->willReturn($acceptLegacy);

        return $settings;
    }

    private function tokenIssuedAt(int $issuedAt, string $contractId, string $userId): string
    {
        return $this->tokenService($this->secrets, $issuedAt)->generateToken($contractId, $userId);
    }

    private function tokenService(InMemoryReturnTokenSecretStore $store, int $now): ContractReturnTokenService
    {
        $settings = $this->settings(false);

        return new class ($store, $settings, $now) extends ContractReturnTokenService {
            public function __construct(
                InMemoryReturnTokenSecretStore $store,
                ReturnAccessSettingsInterface $settings,
                private readonly int $fixedNow,
            ) {
                parent::__construct($store, $settings);
            }

            protected function now(): int
            {
                return $this->fixedNow;
            }
        };
    }

    private function contract(
        string $userId = 'user-1',
        ?string $provider = 'testpsp',
        ?string $initiatingSessionId = 'session-1',
    ): PaymentContractInterface {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getUserId')->willReturn($userId);
        $contract->method('getProvider')->willReturn($provider);
        $contract->method('getMetadata')->willReturnMap([['initiating_session_id', $initiatingSessionId]]);

        return $contract;
    }

    private function request(
        ?PaymentContractInterface $contract = null,
        string $provider = 'testpsp',
        string $token = '',
        string $sessionUserId = 'user-1',
        string $sessionId = 'session-1',
        ?TokenServiceInterface $legacyTokens = null,
    ): ReturnAccessRequest {
        return new ReturnAccessRequest(
            $contract ?? $this->contract(),
            $provider,
            $token,
            $sessionUserId,
            $sessionId,
            $legacyTokens,
        );
    }
}
