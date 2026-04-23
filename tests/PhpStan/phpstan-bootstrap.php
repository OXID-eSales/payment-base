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
        . 'class Registry { '
        . '  public static function getLogger(): \\Psr\\Log\\LoggerInterface { return new \\Psr\\Log\\NullLogger(); } '
        . '  public static function getSession(): StubSession { return new StubSession(); } '
        . '  public static function getRequest(): StubRequest { return new StubRequest(); } '
        . '}'
    );
}
if (!function_exists('oxNew')) {
    eval('function oxNew(string $class, ...$args) { return new $class(...$args); }');
}
