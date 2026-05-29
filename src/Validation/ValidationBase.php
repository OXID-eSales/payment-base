<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

/**
 * Provider-agnostic, character-level field validator.
 *
 * Orchestration order (§4.3 semantics):
 *   1. Empty / null value → valid (non-empty is OXID's RequiredFieldsValidator concern)
 *   2. Unknown field name → valid (documented contract; misuse must not silently reject)
 *   3. Universal blocklist scan (§4.2): control characters / invisible codepoints → controlCharacter
 *   4. Per-character scan: block tokens checked before allow tokens
 *   5. All characters pass → valid
 *
 * Each instance is bound to one $pluginModuleId. The loader is called once
 * on the first validateField call (lazy) and the result is cached.
 */
final class ValidationBase implements ValidationBaseInterface
{
    /** @var array<string, RuleSet>|null */
    private ?array $ruleSets = null;

    public function __construct(
        private readonly string $pluginModuleId,
        private readonly ValidationRuleLoaderInterface $loader,
    ) {
    }

    public function validateField(string $name, mixed $value): FieldValidationResult
    {
        if ($value === null || $value === '') {
            return FieldValidationResult::valid();
        }

        $ruleSet = $this->findRuleSet($name);

        if ($ruleSet === null) {
            return FieldValidationResult::valid();
        }

        $stringValue = (string) $value;

        $controlCharacter = CharacterClass::hasUniversalReject($stringValue);
        if ($controlCharacter !== null) {
            return FieldValidationResult::controlCharacter($controlCharacter);
        }

        return $this->scanCharacters($stringValue, $ruleSet);
    }

    private function findRuleSet(string $name): ?RuleSet
    {
        if ($this->ruleSets === null) {
            $this->ruleSets = $this->loader->loadFor($this->pluginModuleId);
        }

        return $this->ruleSets[$name] ?? null;
    }

    private function scanCharacters(string $value, RuleSet $ruleSet): FieldValidationResult
    {
        $chars = $this->splitIntoCharacters($value);

        foreach ($chars as $char) {
            $result = $this->evaluateCharacter($char, $ruleSet);
            if (!$result->valid) {
                return $result;
            }
        }

        return FieldValidationResult::valid();
    }

    /** @return list<string> */
    private function splitIntoCharacters(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $chars !== false ? $chars : [];
    }

    private function evaluateCharacter(string $char, RuleSet $ruleSet): FieldValidationResult
    {
        foreach ($ruleSet->getBlockTokens() as $blockToken) {
            if ($this->tokenMatches($char, $blockToken)) {
                return FieldValidationResult::blocked($char);
            }
        }

        if ($ruleSet->hasAllowConstraint() && !$this->matchesAnyAllowToken($char, $ruleSet)) {
            return FieldValidationResult::disallowed($char);
        }

        return FieldValidationResult::valid();
    }

    private function matchesAnyAllowToken(string $char, RuleSet $ruleSet): bool
    {
        foreach ($ruleSet->getAllowTokens() as $allowToken) {
            if ($this->tokenMatches($char, $allowToken)) {
                return true;
            }
        }

        return false;
    }

    private function tokenMatches(string $char, string $token): bool
    {
        if (CharacterClass::isClassToken($token)) {
            return CharacterClass::matchesClass($char, $token);
        }

        return $char === $token;
    }
}
