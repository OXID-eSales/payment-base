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
