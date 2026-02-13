<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Contract\Provider;

use OxidEsales\PaymentComponent\Contract\ConditionTypeProviderInterface;

class AgentConditionTypeProvider implements ConditionTypeProviderInterface
{
    public const TYPE_AGENT_IDENTITY_VERIFIED = 'agent_identity_verified';
    public const TYPE_AGENT_CONSENT_CONFIRMED = 'agent_consent_confirmed';

    public function getConditionTypes(): array
    {
        return [
            self::TYPE_AGENT_IDENTITY_VERIFIED,
            self::TYPE_AGENT_CONSENT_CONFIRMED,
        ];
    }
}
