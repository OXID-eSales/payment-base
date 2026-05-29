<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation;

use InvalidArgumentException;

/**
 * Loads per-plugin validation rules from a PHP file at a predictable path.
 *
 * Expected file location inside each plugin:
 *   <pluginRoot>/src/Resources/validation-rules.php
 *
 * The file must return an array of the shape:
 *   ['fields' => [['field' => string, 'rules' => ['allow' => string, 'block' => string]], ...]]
 *
 * Path resolution is delegated to PluginPathResolverInterface so unit tests
 * can stub it without booting the OXID shop.
 */
final class FilesystemValidationRuleLoader implements ValidationRuleLoaderInterface
{
    private const RULES_RELATIVE_PATH = '/src/Resources/validation-rules.php';

    public function __construct(
        private readonly PluginPathResolverInterface $pathResolver,
    ) {
    }

    /**
     * @return array<string, RuleSet>
     * @throws InvalidArgumentException on missing file or malformed shape
     */
    public function loadFor(string $pluginModuleId): array
    {
        $pluginRoot = $this->pathResolver->resolvePath($pluginModuleId);
        $rulesFile = $pluginRoot . self::RULES_RELATIVE_PATH;

        if (!file_exists($rulesFile)) {
            throw new InvalidArgumentException(
                sprintf('Validation rules file not found for plugin "%s": %s', $pluginModuleId, $rulesFile)
            );
        }

        $data = require $rulesFile;

        $this->assertValidShape($data, $pluginModuleId);

        /** @var array{fields: array<array{field: string, rules: array{allow?: string, block?: string}}>} $data */
        return $this->buildRuleSetMap($data['fields']);
    }

    /**
     * @param mixed $data
     * @throws InvalidArgumentException if shape does not match the expected contract
     */
    private function assertValidShape(mixed $data, string $pluginModuleId): void
    {
        if (!is_array($data) || !array_key_exists('fields', $data) || !is_array($data['fields'])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Validation rules file for plugin "%s" must return ["fields" => [...]]',
                    $pluginModuleId
                )
            );
        }
    }

    /**
     * @param array<array{field: string, rules: array{allow?: string, block?: string}}> $fields
     * @return array<string, RuleSet>
     */
    private function buildRuleSetMap(array $fields): array
    {
        $map = [];
        foreach ($fields as $entry) {
            $map[$entry['field']] = RuleSet::fromArray($entry['rules']);
        }

        return $map;
    }
}
