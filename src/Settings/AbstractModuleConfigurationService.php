<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Settings;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Typed access to one module's OXID settings; the provider's configuration service extends it and
 * adds its own getters (mode, keys, capture mode, ...).
 *
 * Two situations look alike and must not be treated alike:
 *  - a setting is unset: a real default applies (metadata.php), {@see get()} answers '' and logs;
 *  - the configuration is unreadable: the module does not know which provider account it points
 *    at. {@see assertReadable()} refuses, so mode and credentials fail closed instead of guessing.
 *    A provider's mode and key getters call it first.
 */
abstract class AbstractModuleConfigurationService
{
    private ?ModuleConfiguration $moduleConfig;
    private bool $configurationUnreadable = false;

    public function __construct(
        ContextInterface $context,
        ModuleConfigurationDaoInterface $moduleConfigurationDao,
        private readonly LoggerInterface $logger,
    ) {
        try {
            $this->moduleConfig = $moduleConfigurationDao->get($this->moduleId(), $context->getCurrentShopId());
        } catch (Throwable $e) {
            $this->moduleConfig = null;
            $this->configurationUnreadable = true;
            $this->logger->error(
                '[ModuleConfiguration] configuration of module ' . $this->moduleId()
                . ' could not be read; mode and credentials fail closed',
                ['moduleId' => $this->moduleId(), 'error' => $e->getMessage()],
            );
        }
    }

    /** OXID module id whose settings this service reads. */
    abstract protected function moduleId(): string;

    public function get(string $name): mixed
    {
        return $this->readSetting($name);
    }

    /**
     * Raw setting value. A seam for test subclasses, which override it without touching the DAO.
     */
    protected function readSetting(string $name): mixed
    {
        if ($this->moduleConfig === null) {
            return '';
        }
        try {
            return $this->moduleConfig->getModuleSetting($name)->getValue();
        } catch (Throwable $e) {
            $this->logger->warning('[ModuleConfiguration] setting unreadable, substituting empty', [
                'moduleId' => $this->moduleId(),
                'setting' => $name,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /** A string setting, '' when unset or of another type. */
    protected function readString(string $name): string
    {
        $value = $this->get($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @throws ModuleConfigurationUnreadableException when the configuration could not be read at all
     */
    protected function assertReadable(): void
    {
        if (!$this->configurationUnreadable) {
            return;
        }

        $this->logger->error(
            '[ModuleConfiguration] refusing to resolve mode/credentials of module ' . $this->moduleId()
            . ' from an unreadable configuration',
            ['moduleId' => $this->moduleId()],
        );

        throw new ModuleConfigurationUnreadableException(
            'Configuration of module ' . $this->moduleId() . ' is unreadable: the active mode and '
            . 'credentials cannot be determined, so no payment may be attempted.'
        );
    }
}
