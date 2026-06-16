<?php

/**
 * Bootstrap file for Unit Tests (no shop context).
 *
 * Loads composer autoload and stubs a minimal
 * `AdminDetailsController` so classes that extend it can be autoloaded
 * without the OXID shop bootstrap. Unit tests never instantiate the real
 * parent — they use testable subclasses that bypass OXID's admin frame.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Controller\\Admin; '
        . 'class AdminDetailsController { '
        . '  /** @var string */ protected $_sThisTemplate; '
        . '  protected array $_aViewData = []; '
        . '  public function __construct() {} '
        . '  public function render() { return $this->_sThisTemplate; } '
        . '  public function getEditObjectId(): string|bool { return false; } '
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

// Sprint 119 — FrontendController stub for ValidationApiController unit tests.
// The controller extends FrontendController; unit tests use a testable subclass
// that calls initWithDependencies() instead of init() (which boots OXID).
if (!class_exists(\OxidEsales\Eshop\Application\Controller\FrontendController::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Controller; '
        . 'class FrontendController { '
        . '  public function init(): void {} '
        . '  public function render(): string { return ""; } '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Eshop\Core\Registry::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class StubUtils { '
        . '  public function setHeader(string $h): void {} '
        . '} '
        . 'class Registry { '
        . '  public static function getLogger(): \\Psr\\Log\\LoggerInterface { return new \\Psr\\Log\\NullLogger(); } '
        . '  public static function getUtils(): StubUtils { return new StubUtils(); } '
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

// Sprint 125 (STRP-157) — stub OxidEsales\Eshop\Core\Price so PriceToTaxableLineMapper
// and PriceListOverrideTest can run without OXID shop bootstrap.
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
// so that PriceList (which extends PriceList_parent) can be autoloaded in unit tests.
// Unit tests never instantiate PriceList directly; they use TestablePriceList subclasses
// that bypass the parent constructor.
// PriceList_parent is resolved in namespace OxidEsales\PaymentBase\Eshop\Core
// (PriceList uses unqualified 'PriceList_parent'). The real alias is created by
// OXID's ModuleChainsGenerator::createClassExtension at module activation.
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
