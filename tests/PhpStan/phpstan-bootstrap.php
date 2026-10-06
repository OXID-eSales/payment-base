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
        // 2026-09-01 — payment-base now owns order finalization, so the stub has
        // to carry the finalizeOrder() surface the adapter uses.
        . '  const ORDER_STATE_OK = 1; '
        . '  const ORDER_STATE_PAYMENTERROR = 2; '
        . '  const ORDER_STATE_ORDEREXISTS = 3; '
        . '  const ORDER_STATE_INVALIDDELIVERY = 4; '
        . '  const ORDER_STATE_INVALIDPAYMENT = 5; '
        . '  const ORDER_STATE_MAILINGERROR = 6; '
        . '  const ORDER_STATE_INVALIDDELADDRESSCHANGED = 7; '
        . '  const ORDER_STATE_BELOWMINPRICE = 8; '
        . '  const ORDER_STATE_VOUCHERERROR = 9; '
        . '  public mixed $oxorder__oxfolder = null; '
        . '  public mixed $oxorder__oxtransid = null; '
        . '  public mixed $oxorder__oxtransstatus = null; '
        . '  public mixed $oxorder__oxordernr = null; '
        . '  public mixed $oxorder__oxorderdate = null; '
        // Sprint 10 (2026-09-23) — OrderShippingAddressCopier writes these
        // OXDEL* columns; declared here purely for static analysis, the same
        // way the fields above already are (OXID's real Order/BaseModel
        // exposes them as dynamic properties at runtime, not as declared ones).
        . '  public mixed $oxorder__oxdelcompany = null; '
        . '  public mixed $oxorder__oxdelfname = null; '
        . '  public mixed $oxorder__oxdellname = null; '
        . '  public mixed $oxorder__oxdelstreet = null; '
        . '  public mixed $oxorder__oxdelstreetnr = null; '
        . '  public mixed $oxorder__oxdeladdinfo = null; '
        . '  public mixed $oxorder__oxdelcity = null; '
        . '  public mixed $oxorder__oxdelcountryid = null; '
        . '  public mixed $oxorder__oxdelstateid = null; '
        . '  public mixed $oxorder__oxdelzip = null; '
        . '  public mixed $oxorder__oxdelfon = null; '
        . '  public mixed $oxorder__oxdelfax = null; '
        . '  public mixed $oxorder__oxdelsal = null; '
        . '  public function load(string $oxid): bool { return false; } '
        . '  public function getId(): ?string { return null; } '
        . '  public function getFieldData(string $field): mixed { return null; } '
        . '  public function save(): mixed { return true; } '
        . '  public function assign($data): mixed { return true; } '
        . '  public function finalizeOrder($basket, $user, $recalculate = false): int { return 1; } '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Eshop\Core\Field::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class Field { '
        . '  const T_RAW = 1; '
        . '  const T_TEXT = 2; '
        . '  public mixed $value = null; '
        . '  public function __construct($value = null, $type = 1) { $this->value = $value; } '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Eshop\Application\Model\Basket::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class Basket { '
        . '  public function getProductsCount(): int { return 0; } '
        . '  public function getPrice(): mixed { return null; } '
        . '  public function getBasketCurrency(): ?object { return null; } '
        . '  public function getBasketUser(): mixed { return null; } '
        // Sprint 15 / S1 (2026-10-06) — writers UserBasketProvider uses to build
        // the basket core finalizes from an oxuserbaskets row.
        . '  public function setBasketUser(mixed $oUser): void {} '
        . '  public function addToBasket(string $sProductID, float $dAmount, mixed $aSel = null, mixed $aPersParam = null, bool $blOverride = false, bool $blBundle = false, ?string $sOldBasketItemId = null): mixed { return null; } '
        . '  public function setPayment(?string $sPaymentId = null): void {} '
        . '  public function setShipping(?string $sShippingSetId = null): void {} '
        . '  public function calculateBasket(bool $blForceUpdate = false): void {} '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Eshop\Application\Model\User::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . '#[\\AllowDynamicProperties] '
        . 'class User { '
        . '  public mixed $oxuser__oxactive = null; '
        . '  public mixed $oxuser__oxrights = null; '
        . '  public mixed $oxuser__oxshopid = null; '
        . '  public mixed $oxuser__oxpassword = null; '
        . '  public function load(string $oxid): bool { return false; } '
        . '  public function getId(): ?string { return null; } '
        . '  public function getEncodedDeliveryAddress(): string { return ""; } '
        . '  public function save(): mixed { return true; } '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Eshop\Core\Exception\ArticleException::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core\\Exception; '
        . 'class ArticleException extends \\Exception {}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Core\StubSession::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class StubSession { '
        . '  public function getSessionChallengeToken(): string { return ""; } '
        . '  public function getId(): string { return ""; } '
        . '  public function getVariable(string $name): mixed { return null; } '
        . '  public function setVariable(string $name, mixed $value): void {} '
        . '  public function deleteVariable(string $name): void {} '
        . '  public function getBasket(): mixed { return null; } '
        . '  public function setBasket(object $basket): void {} '
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
        . '  public function redirect(string $url, bool $addParams = true, int $status = 302): void {} '
        . '} '
        . 'class StubConfig { '
        . '  public function getConfigParam(string $name): mixed { return null; } '
        . '  public function getShopUrl(): string { return ""; } '
        . '  public function getShopSecureHomeUrl(): string { return ""; } '
        . '  public function getShopId(): int { return 1; } '
        . '  public function getModuleVar(string $name, string $moduleId): mixed { return null; } '
        . '} '
        . 'class Registry { '
        . '  public static function getLogger(): \\Psr\\Log\\LoggerInterface { return new \\Psr\\Log\\NullLogger(); } '
        . '  public static function getSession(): StubSession { return new StubSession(); } '
        . '  public static function getRequest(): StubRequest { return new StubRequest(); } '
        . '  public static function getUtils(): StubUtils { return new StubUtils(); } '
        . '  public static function getConfig(): StubConfig { return new StubConfig(); } '
        . '  public static function get(string $class): mixed { return null; } '
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
        // Sprint 15 / S5 (2026-10-06): the real facade answers a UnicodeString,
        // not a string - ReturnUrlSettings calls ->toString() on it.
        . '  public function getString(string $name, string $moduleId): \\Symfony\\Component\\String\\UnicodeString; '
        . '  public function getInteger(string $name, string $moduleId): int; '
        . '}'
    );
}

// Sprint 08 (2026-08-28) — stub ModuleSettingBridgeInterface for static analysis of
// NotFinishedOrderCleanupSettings, which resolves it from the DI container.
// The bridge, not the typed facade: OXID stores a 'num' setting as a string, so
// the facade's getInteger(): int throws a TypeError on its own stored value.
if (!interface_exists(\OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface::class, false)) {
    eval(
        'namespace OxidEsales\\EshopCommunity\\Internal\\Framework\\Module\\Configuration\\Bridge; '
        . 'interface ModuleSettingBridgeInterface { '
        . '  public function save(string $name, mixed $value, string $moduleId): void; '
        . '  public function get(string $name, string $moduleId): mixed; '
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

// Sprint 06 — stubs for the single-payment checkout extensions.
// PaymentController_parent / OrderController_parent are OXID virtual classes
// created by ModuleChainsGenerator at activation; they resolve in the
// extension's own namespace. Unit tests never instantiate the real parents —
// testable subclasses override the seams.
if (!class_exists(\OxidEsales\PaymentBase\Eshop\Application\Controller\PaymentController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\PaymentBase\\Eshop\\Application\\Controller; '
        . 'class PaymentController_parent { '
        . '  public function render() { return ""; } '
        . '  public function getPaymentList() { return []; } '
        . '  public function getPaymentError() { return null; } '
        . '  public function getUser() { return null; } '
        . '  public function getAllSets() { return []; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\PaymentBase\Eshop\Application\Controller\OrderController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\PaymentBase\\Eshop\\Application\\Controller; '
        . 'class OrderController_parent { '
        . '  public function render() { return ""; } '
        . '  public function getPayment() { return false; } '
        . '  public function getBasket() { return false; } '
        . '  public function getUser() { return null; } '
        . '  public function getShipSet() { return false; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Application\Model\Payment::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class Payment { '
        . '  public function load(string $oxid): bool { return false; } '
        . '  public function getId(): ?string { return null; } '
        . '  public function getDynValues(): ?array { return []; } '
        . '  public function isValidPayment($dynValue, $shopId, $user, $basketPrice, $shipSetId): bool { return false; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Application\Model\PaymentList::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class PaymentList { '
        . '  public function getPaymentList($shipSetId, $price, $user = null): array { return []; } '
        . '}'
    );
}
// Sprint 07 — DeliverySetList stub. OrderController::readAvailableDeliverySetList()
// resolves it through Registry::get(); under unit conditions there is no basket, which
// is exactly the "the shop cannot answer" case the read has to survive.
if (!class_exists(\OxidEsales\Eshop\Application\Model\DeliverySetList::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class DeliverySetList { '
        . '  public function getDeliverySetData($shipSet, $user, $basket): array { return [[], null, []]; } '
        . '}'
    );
}

// 2026-09-01 — ThankYouController_parent is an OXID virtual class created by
// ModuleChainsGenerator at activation; it resolves in the extension's own
// namespace. Unit tests never instantiate the real parent.
if (!class_exists(\OxidEsales\PaymentBase\Eshop\Application\Controller\ThankYouController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\PaymentBase\\Eshop\\Application\\Controller; '
        . 'class ThankYouController_parent { '
        . '  public function render() { return ""; } '
        . '}'
    );
}

// Sprint 09 (2026-09-03) — Order_parent, the OXID virtual class the order
// extension extends. Stubbed here rather than suppressed with
// @phpstan-ignore, so the parent calls and getId() stay type-checked.
if (!class_exists(\OxidEsales\PaymentBase\Eshop\Application\Model\Order_parent::class, false)) {
    eval(
        'namespace OxidEsales\\PaymentBase\\Eshop\\Application\\Model; '
        . 'class Order_parent { '
        . '  public function delete($sOxId = null) { return true; } '
        . '  public function cancelOrder() {} '
        . '  public function getId() { return ""; } '
        . '  public function save() { return true; } '
        . '}'
    );
}

// Sprint 15 / S1 (2026-10-06) — the persisted basket of a headless checkout
// (`oxuserbaskets` / `oxuserbasketitems`) that UserBasketProvider reads.
if (!class_exists(\OxidEsales\Eshop\Application\Model\UserBasket::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . '#[\\AllowDynamicProperties] '
        . 'class UserBasket { '
        . '  public mixed $oxuserbaskets__oxuserid = null; '
        . '  public mixed $oxuserbaskets__oxtitle = null; '
        . '  public mixed $oxuserbaskets__oxpublic = null; '
        . '  public mixed $oxuserbaskets__oegql_paymentid = null; '
        . '  public mixed $oxuserbaskets__oegql_deliverymethodid = null; '
        . '  public function load(string $oxid): bool { return false; } '
        . '  public function getId(): ?string { return null; } '
        . '  public function getFieldData(string $field): mixed { return null; } '
        . '  /** @return list<\\OxidEsales\\Eshop\\Application\\Model\\UserBasketItem> */ '
        . '  public function getItems(bool $blReload = false, bool $blActiveCheck = true): array { return []; } '
        . '  public function delete(?string $oxid = null): bool { return true; } '
        . '  public function setId(?string $oxid = null): string { return (string) $oxid; } '
        . '  public function save(): mixed { return true; } '
        . '  public function addItemToBasket(?string $productId = null, ?float $amount = null, mixed $sel = null, bool $override = false, mixed $persParam = null): mixed { return null; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\Eshop\Application\Model\UserBasketItem::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class UserBasketItem { '
        . '  public function getFieldData(string $field): mixed { return null; } '
        . '  public function getSelList(): mixed { return null; } '
        . '  public function getPersParams(): mixed { return null; } '
        . '}'
    );
}

// Sprint 15 / S5 (2026-10-06) — symfony/string ships with the shop, not with
// payment-base's own vendor; the facade stub above returns it.
if (!class_exists(\Symfony\Component\String\UnicodeString::class, false)) {
    eval(
        'namespace Symfony\\Component\\String; '
        . 'class UnicodeString { '
        . '  public function toString(): string { return ""; } '
        . '  public function __toString(): string { return ""; } '
        . '}'
    );
}

// Sprint 15 / S6 (2026-10-06) — the GraphQL glue implements graphql-base /
// GraphQLite / graphql-storefront / Symfony contracts that ship with the shop,
// not with payment-base's own vendor. Minimal stubs so the glue is analysable.
if (!interface_exists(\OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Base\\Framework; '
        . 'interface NamespaceMapperInterface { '
        . '  public function getControllerNamespaceMapping(): array; '
        . '  public function getTypeNamespaceMapping(): array; '
        . '} '
        . 'interface PermissionProviderInterface { public function getPermissions(): array; }'
    );
}
if (!class_exists(\GraphQL\Error\Error::class, false)) {
    eval('namespace GraphQL\\Error; class Error extends \\Exception {}');
}
if (!class_exists(\OxidEsales\GraphQL\Base\Exception\Error::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Base\\Exception; '
        . 'abstract class Error extends \\GraphQL\\Error\\Error { '
        . '  public function __construct(string $message, protected $code = 0, ?\\Throwable $previous = null, protected string $category = "Exception", array $extensions = []) { parent::__construct($message, 0, $previous); } '
        . '  public function getCategory(): string { return $this->category; } '
        . '} '
        . 'class ErrorCategories { '
        . '  public const PERMISSIONERRORS = "permissionerror"; public const TOKENERRORS = "tokenerror"; '
        . '  public const CONFIGURATIONERROR = "configurationerror"; public const REQUESTERROR = "requesterror"; '
        . '}'
    );
}
if (!class_exists(\TheCodingMachine\GraphQLite\Types\ID::class, false)) {
    eval(
        'namespace TheCodingMachine\\GraphQLite\\Types; '
        . 'class ID { public function __construct(private mixed $value) {} public function val(): mixed { return $this->value; } public function __toString(): string { return (string) $this->value; } }'
    );
}
if (!class_exists(\TheCodingMachine\GraphQLite\Annotations\Type::class, false)) {
    eval(
        'namespace TheCodingMachine\\GraphQLite\\Annotations; '
        . '#[\\Attribute(\\Attribute::TARGET_CLASS)] class Type { public function __construct(mixed ...$args) {} } '
        . '#[\\Attribute(\\Attribute::TARGET_METHOD)] class Field { public function __construct(mixed ...$args) {} }'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Storefront\Basket\Event\BeforePlaceOrder::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Storefront\\Basket\\Event; '
        . 'final class BeforePlaceOrder { '
        . '  public function __construct(private \\TheCodingMachine\\GraphQLite\\Types\\ID $basketId) {} '
        . '  public function getBasketId(): \\TheCodingMachine\\GraphQLite\\Types\\ID { return $this->basketId; } '
        . '}'
    );
}
if (!interface_exists(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class, false)) {
    eval(
        'namespace Symfony\\Component\\EventDispatcher; '
        . 'interface EventSubscriberInterface { public static function getSubscribedEvents(): array; }'
    );
}

// Sprint 15 / S7 (2026-10-06) — GuestUserResolver maps ISO 3166-1 alpha-2 to the shop's country id.
if (!class_exists(\OxidEsales\Eshop\Application\Model\Country::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Application\\Model; '
        . 'class Country { public function getIdByCode(string $code): mixed { return null; } }'
    );
}
if (!class_exists(\OxidEsales\Eshop\Core\DatabaseProvider::class, false)) {
    eval(
        'namespace OxidEsales\\Eshop\\Core; '
        . 'class StubDb { public function getOne(string $sql, array $params = []): mixed { return null; } } '
        . 'class DatabaseProvider { public static function getDb(): StubDb { return new StubDb(); } }'
    );
}
