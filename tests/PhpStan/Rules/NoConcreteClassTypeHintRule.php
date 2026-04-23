<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionClass;

/**
 * PHPStan rule that enforces using interfaces instead of concrete classes for method parameters.
 *
 * This rule implements the Liskov Substitution Principle (LSP) by requiring that:
 * - Method parameters use interfaces, not concrete classes
 *
 * Exceptions (allowed concrete classes):
 * - PHP built-in classes (DateTime, Exception, ReflectionClass, etc.)
 * - Value objects and entities (classes in Contract/ namespace)
 * - Test doubles and mocks
 *
 * @implements Rule<ClassMethod>
 */
final class NoConcreteClassTypeHintRule implements Rule
{
    /**
     * Allowed concrete class patterns (regex).
     * These are exceptions where concrete classes are acceptable.
     *
     * @var array<string>
     */
    private const ALLOWED_PATTERNS = [
        // PHP built-in classes
        '#^(DateTime|DateTimeImmutable|DateTimeInterface|Exception|Error|Throwable)#',
        '#^Reflection#',
        '#^(stdClass|ArrayObject|SplFileInfo|Closure)#',

        // Doctrine DBAL (infrastructure)
        '#^Doctrine\\\\DBAL\\\\#',

        // Value objects, entities, and DTOs (domain objects are allowed as concrete)
        '#\\\\Contract\\\\#',
        '#\\\\Entity\\\\#',
        '#\\\\ValueObject\\\\#',
        '#\\\\Event\\\\#',
        '#\\\\Request\\\\#',  // Request DTOs
        '#\\\\Response\\\\#', // Response DTOs
        '#\\\\Result\\\\#',   // Result value objects
        '#\\\\Return\\\\#',   // Return resolution DTOs (Sprint A)
        '#\\\\Transaction\\\\#', // Transaction entities
        '#\\\\Webhook\\\\#',  // Webhook entities
        '#\\\\Order\\\\#',    // Order entities
        '#\\\\Admin\\\\Panel\\\\#', // Sprint I admin-panel DTOs (context + renderable)
        '#\\\\Admin\\\\PaymentAdminActionDispatcher$#', // Sprint I admin action dispatcher

        // Test classes
        '#Test$#',
        '#Mock#',
        '#Stub#',
        '#Fake#',

        // PSR interfaces (these ARE interfaces, but check just in case)
        '#^Psr\\\\#',
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @param ClassMethod $node
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return $this->checkMethodParameters($node, $scope);
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    private function checkMethodParameters(ClassMethod $method, Scope $scope): array
    {
        $errors = [];

        foreach ($method->params as $param) {
            if ($param->type === null) {
                continue;
            }

            $typeName = $this->getTypeName($param->type);
            if ($typeName === null) {
                continue;
            }

            if ($this->isViolation($typeName)) {
                $paramName = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    ? $param->var->name
                    : 'unknown';

                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'Parameter $%s uses concrete class "%s" instead of an interface. ' .
                        'Use an interface to follow Liskov Substitution Principle.',
                        $paramName,
                        $typeName
                    )
                )->identifier('lsp.concreteClassParameter')->build();
            }
        }

        return $errors;
    }

    private function getTypeName(Node $typeNode): ?string
    {
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }

        if ($typeNode instanceof Node\Name\FullyQualified) {
            return $typeNode->toString();
        }

        if ($typeNode instanceof Node\NullableType) {
            return $this->getTypeName($typeNode->type);
        }

        if ($typeNode instanceof Node\UnionType || $typeNode instanceof Node\IntersectionType) {
            // For union/intersection types, check each type individually
            // Return null to skip - this gets complex
            return null;
        }

        if ($typeNode instanceof Node\Identifier) {
            // Built-in types like 'string', 'int', 'array', 'object', etc.
            return null;
        }

        return null;
    }

    private function isViolation(string $className): bool
    {
        // Check if it's in the allowed patterns
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if (preg_match($pattern, $className)) {
                return false;
            }
        }

        // Check if the class exists and is an interface (then it's fine)
        if (interface_exists($className) || trait_exists($className)) {
            return false;
        }

        // Check if it's abstract - abstract classes are borderline acceptable
        if (class_exists($className)) {
            try {
                $reflection = new ReflectionClass($className);
                if ($reflection->isInterface() || $reflection->isAbstract()) {
                    return false;
                }
                // It's a concrete class - this is a violation
                return true;
            } catch (\ReflectionException $e) {
                // Can't reflect, assume it's okay
                return false;
            }
        }

        // Class doesn't exist (maybe not autoloaded), assume it's okay
        return false;
    }
}
