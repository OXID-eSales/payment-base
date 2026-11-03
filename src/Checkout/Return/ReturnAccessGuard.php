<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The one check in front of every return leg, whichever provider it belongs to: the contract
 * exists and is this provider's, and the caller either holds a valid return token or is the
 * contract's shopper in the session that started the payment. Unknown ownership is a rejection -
 * a charged payment is not thrown away by a check that cannot be made, it is stopped before it
 * commits and left for the provider's webhook, which does not depend on a browser.
 */
final class ReturnAccessGuard implements ReturnAccessGuardInterface
{
    public const METADATA_INITIATING_SESSION = 'initiating_session_id';

    private const NEW_TOKEN_MARK = '.';

    public function __construct(
        private readonly ContractReturnTokenServiceInterface $tokens,
        private readonly ReturnAccessSettingsInterface $settings,
    ) {
    }

    public function check(ReturnAccessRequest $request): ReturnAccessDecision
    {
        $contract = $request->contract;
        $contractId = $contract?->getId();
        if ($contract === null || $contractId === null) {
            return ReturnAccessDecision::reject(ReturnAccessRejection::ContractNotFound);
        }
        if ($contract->getProvider() !== $request->provider) {
            return ReturnAccessDecision::reject(ReturnAccessRejection::ProviderMismatch);
        }

        $verdict = $this->tokens->verify($request->token, $contractId, $contract->getUserId());
        if ($verdict === ReturnTokenVerdict::Valid || $this->legacyTokenHolds($request, $contractId)) {
            return ReturnAccessDecision::grant();
        }
        if ($this->isPayingShopperInInitiatingSession($request, $contract)) {
            return ReturnAccessDecision::grant();
        }

        return ReturnAccessDecision::reject(
            $verdict === ReturnTokenVerdict::Expired
                ? ReturnAccessRejection::TokenExpired
                : ReturnAccessRejection::AccessDenied
        );
    }

    private function legacyTokenHolds(ReturnAccessRequest $request, string $contractId): bool
    {
        if ($request->legacyTokens === null || !$this->settings->acceptsLegacyTokens()) {
            return false;
        }
        if ($request->token === '' || str_contains($request->token, self::NEW_TOKEN_MARK)) {
            return false;
        }

        return $request->legacyTokens->validateToken($request->token, $contractId);
    }

    private function isPayingShopperInInitiatingSession(
        ReturnAccessRequest $request,
        PaymentContractInterface $contract,
    ): bool {
        $owner = $contract->getUserId();
        $initiatingSession = $contract->getMetadata(self::METADATA_INITIATING_SESSION);
        if ($owner === '' || $request->sessionUserId !== $owner) {
            return false;
        }

        return is_string($initiatingSession) && $initiatingSession !== '' && $initiatingSession === $request->sessionId;
    }
}
