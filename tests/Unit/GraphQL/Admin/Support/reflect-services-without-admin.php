<?php

/**
 * Run in a child process by AdminApiIsOptionalTest: loads and reflects every service class of payment-base's
 * admin payment API the way the container compiler does at module activation, in a PHP process where NOTHING of
 * oxid-admin-graphql, graphql-base, graphql-storefront or GraphQLite exists. Prints "OK <count>", or the
 * fatal error PHP raises.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$moduleRoot = dirname(__DIR__, 5);
require $moduleRoot . '/vendor/autoload.php';

// The shop always ships the Symfony event dispatcher; the module's vendor only has it as a dev stand-in.
if (!interface_exists(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class)) {
    eval('namespace Symfony\\Component\\EventDispatcher; interface EventSubscriberInterface { public static function getSubscribedEvents(): array; }');
}

foreach (['OxidEsales\\GraphQL\\Admin\\Order\\DataType\\AdminOrder', 'TheCodingMachine\\GraphQLite\\Annotations\\Field', 'OxidEsales\\GraphQL\\Base\\Framework\\NamespaceMapperInterface'] as $mustBeAbsent) {
    if (class_exists($mustBeAbsent) || interface_exists($mustBeAbsent)) {
        echo "NOT ABSENT: $mustBeAbsent\n";
        exit(2);
    }
}

$services = Yaml::parseFile($moduleRoot . '/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
$reflected = 0;
foreach ($services as $id => $definition) {
    $class = is_array($definition) && isset($definition['class']) ? $definition['class'] : $id;
    if (!str_contains((string) $class, 'PaymentBase\\GraphQL\\Admin\\')) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getAttributes() as $attribute) {
        $attribute->getName();
    }
    foreach ($reflection->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $parameter->getName();
            $parameter->getType()?->__toString();
        }
        $method->getReturnType()?->__toString();
        foreach ($method->getAttributes() as $attribute) {
            $attribute->getName();
        }
    }
    ++$reflected;
}

echo "OK $reflected\n";
