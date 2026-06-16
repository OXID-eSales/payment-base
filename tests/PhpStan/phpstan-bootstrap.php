<?php

/**
 * PHPStan Bootstrap File
 *
 * This file is loaded before PHPStan analyzes the codebase.
 * Define any constants or load any files needed for analysis.
 */

declare(strict_types=1);

// Define constants that may be needed during analysis
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', dirname(__DIR__, 2) . '/vendor/');
}

// Load custom PHPStan rules
require_once __DIR__ . '/Rules/NoConcreteClassTypeHintRule.php';

// Stub OXID core classes that PC's admin controller extends/references.
// The shop isn't in PC's composer deps (PC is a dependency of the shop,
// not the other way around), so static analysis needs explicit stubs.
if (!class_exists(\OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Controller\\Admin; '
        . 'class AdminDetailsController { '
        . '  /** @var string */ protected $_sThisTemplate; '
        . '  protected array $_aViewData = []; '
        . '  public function __construct() {} '
        . '  public function render() { return $this->_sThisTemplate; } '
        . '  public function getEditObjectId(): string { return ""; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Application\Model\Order::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class Order { '
        . '  public function load(string $oxid): bool { return false; } '
        . '  public function getId(): ?string { return null; } '
        . '  public function getFieldData(string $field): mixed { return null; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Core\StubSession::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class StubSession { '
        . '  public function getSessionChallengeToken(): string { return ""; } '
        . '  public function getId(): string { return ""; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Core\StubRequest::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class StubRequest { '
        . '  public function getRequestEscapedParameter(string $name, mixed $default = null): mixed { return $default; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Core\Registry::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class StubUtils { '
        . '  public function setHeader(string $h): void {} '
        . '} '
        . 'class StubConfig { '
        . '  public function getShopUrl(): string { return ""; } '
        . '  public function getModuleVar(string $name, string $moduleId): mixed { return null; } '
        . '} '
        . 'class Registry { '
        . '  public static function getLogger(): \\Psr\\Log\\LoggerInterface { return new \\Psr\\Log\\NullLogger(); } '
        . '  public static function getSession(): StubSession { return new StubSession(); } '
        . '  public static function getRequest(): StubRequest { return new StubRequest(); } '
        . '  public static function getUtils(): StubUtils { return new StubUtils(); } '
        . '  public static function getConfig(): StubConfig { return new StubConfig(); } '
        . '}'
    );
}
if (!function_exists('oxNew')) {
    eval('function oxNew(string $class, ...$args) { return new $class(...$args); }');
}

// Sprint 119 — stub OXID internal module-configuration classes used by
// OxidPluginPathResolver. These classes live in oxideshop-ce which is NOT
// in payment-base's composer deps (PC is a library, not a shop). The stubs
// give PHPStan enough type information to analyse the class without booting
// the shop. The real implementations are injected at runtime via the shop DI.
if (!interface_exists(\OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Framework\\Module\\Configuration\\Dao; '
        . 'interface ModuleConfigurationDaoInterface { '
        . '  public function get(string $moduleId, int $shopId): '
        . '    \\OxidEsales\\EshopCommunity\\Internal\\Framework\\Module\\Configuration\\DataObject\\ModuleConfiguration; '
        . '}'
    );
}
if (!class_exists(\OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Framework\\Module\\Configuration\\DataObject; '
        . 'class ModuleConfiguration { '
        . '  public function getModuleSource(): string { return ""; } '
        . '  public function getId(): string { return ""; } '
        . '}'
    );
}
if (!interface_exists(\OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContextInterface::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Transition\\Utility; '
        . 'interface BasicContextInterface { '
        . '  public function getShopRootPath(): string; '
        . '  public function getCurrentShopId(): int; '
        . '}'
    );
}

// Sprint 119 — stubs for ValidationApiController (extends FrontendController)
// and its OXID dependencies used in the production init() method.
if (!class_exists(\OxidEsales\Eshop\Application\Controller\FrontendController::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Controller; '
        . 'class FrontendController { '
        . '  public function init(): void {} '
        . '  public function render(): string { return ""; } '
        . '}'
    );
}

if (!interface_exists(\OxidEsales\EshopCommunity\Internal\Framework\Module\Setup\Bridge\ModuleActivationBridgeInterface::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Framework\\Module\\Setup\\Bridge; '
        . 'interface ModuleActivationBridgeInterface { '
        . '  public function isActive(string $moduleId, int $shopId): bool; '
        . '}'
    );
}

if (!class_exists(\OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Container; '
        . 'class StubContainer { '
        . '  public function get(string $id): mixed { return null; } '
        . '} '
        . 'class ContainerFactory { '
        . '  private static ?self $instance = null; '
        . '  public static function getInstance(): self { if (self::$instance === null) { self::$instance = new self(); } return self::$instance; } '
        . '  public function getContainer(): StubContainer { return new StubContainer(); } '
        . '}'
    );
}

// Sprint 125 (STRP-157) — stub ModuleSettingServiceInterface for static analysis of
// PriceList::isPerLineEnabled() which resolves it from the DI container.
if (!interface_exists(\OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Framework\\Module\\Facade; '
        . 'interface ModuleSettingServiceInterface { '
        . '  public function getBoolean(string $name, string $moduleId): bool; '
        . '  public function getString(string $name, string $moduleId): string; '
        . '  public function getInteger(string $name, string $moduleId): int; '
        . '}'
    );
}

// Sprint 125 (STRP-157) — stub OxidEsales\Eshop\Core\Price for static analysis of
// PriceToTaxableLineMapper (which accepts Price as a parameter).
if (!class_exists(\OxidEsales\Eshop\Core\Price::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class Price { '
        . '  public function getPrice(): float { return 0.0; } '
        . '  public function getVat(): float { return 0.0; } '
        . '}'
    );
}

// Sprint 125 (STRP-157) — stub PriceList_parent (OXID virtual class generated at activation)
// so PHPStan can analyse PriceList which extends PriceList_parent.
// PriceList_parent is resolved in the namespace OxidEsales\PaymentBase\Eshop\Core
// (because PriceList uses unqualified 'PriceList_parent', not '\PriceList_parent').
// The real alias is created by OXID's ModuleChainsGenerator::createClassExtension at runtime.
if (!class_exists(\OxidEsales\PaymentBase\Eshop\Core\PriceList_parent::class, false)) {
    eval(
        'namespace OxidEsales\\PaymentBase\\Eshop\\Core; '
        . 'class PriceList_parent { '
        . '  protected array $_aList = []; '
        . '  public function __construct() {} '
        . '  public function getVatInfo($isNettoMode = true) { return []; } '
        . '}'
    );
}
