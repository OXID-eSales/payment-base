<?php

/**
 * Stand-ins for the classes of oxid-admin-graphql (and the GraphQLite attributes of its operations) that
 * payment-base's admin payment API relies on. The admin API is optional for payment-base, so none of it is in
 * this module's vendor: the unit suite and PHPStan load this file instead. Minimal, with the shape payment-base
 * uses; tests/Integration/GraphQL/Admin/AdminModuleParityTest proves it against the real classes.
 */

declare(strict_types=1);

if (!class_exists(\OxidEsales\GraphQL\Admin\Authorization\DataType\RightDefinition::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Authorization\\DataType; '
        . 'final class RightDefinition { '
        . '  private function __construct(private string $right, private string $domain, private string $description) {} '
        . '  public static function of(string $right, string $domain, string $description): self { '
        . '    if (preg_match("/^ADMIN_[A-Z0-9]+(_[A-Z0-9]+)*$/", $right) !== 1) { throw new \\InvalidArgumentException("Not an admin right name: " . $right); } '
        . '    return new self($right, $domain, $description); } '
        . '  public function right(): string { return $this->right; } '
        . '  public function domain(): string { return $this->domain; } '
        . '  public function description(): string { return $this->description; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Admin\Order\DataType\AdminOrder::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Order\\DataType; '
        . 'final class AdminOrder { public function __construct(public readonly string $id) {} } '
        . 'enum OrderChangeKind: string { case Items = "items"; case Addresses = "addresses"; case Payment = "payment"; case DeliverySet = "deliverySet"; } '
        . 'abstract class AdminOrderEvent { '
        . '  public function __construct(public readonly string $orderId, public readonly int $shopId, public readonly string $paymentTypeId, public readonly string $transactionId) {} '
        . '} '
        . 'final class AdminOrderCancelled extends AdminOrderEvent {} '
        . 'final class AdminOrderChangeGuard extends AdminOrderEvent { '
        . '  private ?string $refusal = null; '
        . '  public function __construct(string $orderId, int $shopId, string $paymentTypeId, string $transactionId, public readonly OrderChangeKind $change) { parent::__construct($orderId, $shopId, $paymentTypeId, $transactionId); } '
        . '  public function refuse(string $reason): void { $this->refusal ??= $reason; } '
        . '  public function isRefused(): bool { return $this->refusal !== null; } '
        . '  public function reason(): string { return $this->refusal ?? ""; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Admin\Checkout\DataType\AdminPaymentOwnership::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Checkout\\DataType; '
        . 'final class PaymentClaim { '
        . '  public const FIELDS = ["title", "description", "active", "sort", "surchargeAmount", "surchargeType", "surchargeBase", "fromAmount", "toAmount", "checked"]; '
        . '  public const DEFAULT_READ_ONLY = ["title", "description"]; '
        . '  public function __construct(public readonly string $provider, public readonly array $readOnlyFields) { '
        . '    $unknown = array_diff($readOnlyFields, self::FIELDS); '
        . '    if ($unknown !== []) { throw new \\InvalidArgumentException("Not a payment field: " . implode(", ", $unknown)); } } '
        . '} '
        . 'final class AdminPaymentOwnership { '
        . '  private ?PaymentClaim $claim = null; '
        . '  public function __construct(public readonly string $paymentId) {} '
        . '  public function claim(string $provider, ?array $readOnlyFields = null): void { $this->claim ??= new PaymentClaim($provider, $readOnlyFields ?? PaymentClaim::DEFAULT_READ_ONLY); } '
        . '  public function claimed(): ?PaymentClaim { return $this->claim; } '
        . '}'
    );
}
if (!interface_exists(\OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Shared\\DataType\\Error; '
        . 'interface AdminError { public function message(): string; public function errorCode(): string; } '
        . 'abstract class AbstractAdminError implements AdminError { '
        . '  public function __construct(private string $code, private ?string $text = null) {} '
        . '  public function message(): string { return $this->text ?? $this->code; } '
        . '  public function errorCode(): string { return $this->code; } '
        . '} '
        . 'final class NotFoundError extends AbstractAdminError { public function __construct(?string $message = null) { parent::__construct("not_found", $message); } } '
        . 'final class ValidationError extends AbstractAdminError { '
        . '  public function __construct(private string $field, private string $reason, ?string $message = null) { parent::__construct("validation_failed", $message); } '
        . '  public function field(): string { return $this->field; } '
        . '  public function code(): string { return $this->reason; } '
        . '}'
    );
}
if (!interface_exists(\OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Shared\\DataType\\Payload; '
        . 'interface PayloadWithErrors { public function errors(): array; }'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Admin\Mfa\Service\StepUp::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Mfa\\Service; '
        . 'class StepUp { '
        . '  public const FIVE_MINUTES = 300; '
        . '  public function require(int $maxAgeSeconds = self::FIVE_MINUTES): mixed { return null; } '
        . '}'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Audit\\DataType; '
        . 'final class AuditTrail { '
        . '  public string $entityType = ""; public string $entityId = ""; public array $fields = []; public array $values = []; '
        . '  public function __construct(public readonly string $operation) {} '
        . '  public function on(string $entityType, string $entityId): self { $this->entityType = $entityType; $this->entityId = $entityId; return $this; } '
        . '  public function field(string ...$names): self { $this->fields = [...$this->fields, ...$names]; return $this; } '
        . '  public function value(string $name, bool|int|float|string|array $value): self { $this->values[$name] = $value; return $this; } '
        . '} '
        . 'namespace OxidEsales\\GraphQL\\Admin\\Audit\\Service; '
        . 'class AuditRecorder { '
        . '  public function auditedAdmin(string $operation, \\Closure $action): mixed { return $action(new \\OxidEsales\\GraphQL\\Admin\\Audit\\DataType\\AuditTrail($operation)); } '
        . '}'
    );
}

// The GraphQLite attributes of the admin payment operations; each declared only when no other bootstrap did.
if (!class_exists(\TheCodingMachine\GraphQLite\Annotations\Mutation::class, false)) {
    eval('namespace TheCodingMachine\\GraphQLite\\Annotations; #[\\Attribute(\\Attribute::TARGET_METHOD)] class Mutation { public function __construct(mixed ...$args) {} }');
}
if (!class_exists(\TheCodingMachine\GraphQLite\Annotations\Right::class, false)) {
    eval('namespace TheCodingMachine\\GraphQLite\\Annotations; #[\\Attribute(\\Attribute::TARGET_METHOD | \\Attribute::TARGET_PROPERTY)] class Right { public function __construct(mixed ...$args) {} }');
}
if (!class_exists(\TheCodingMachine\GraphQLite\Annotations\ExtendType::class, false)) {
    eval('namespace TheCodingMachine\\GraphQLite\\Annotations; #[\\Attribute(\\Attribute::TARGET_CLASS)] class ExtendType { public function __construct(mixed ...$args) {} }');
}
