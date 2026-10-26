<?php

/**
 * Run in a child process by GraphQLIsOptionalTest: declares every class, interface, trait and enum of payment-base
 * outside src/GraphQL/ in a PHP process where NOTHING of graphql-base, graphql-storefront, oxid-admin-graphql,
 * GraphQLite or webonyx exists, and builds the exceptions that used to extend graphql-base's Error. Prints
 * "OK <count>", or the fatal error PHP raises (preceded by the symbol that was being loaded).
 *
 * Deliberately NOT tests/bootstrap-unit.php: that file stubs the GraphQL classes, which would hide the very defect
 * this process exists to find.
 */

declare(strict_types=1);

use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;

$moduleRoot = dirname(__DIR__, 3);
require $moduleRoot . '/vendor/autoload.php';

// The shop always ships the Symfony event dispatcher; the module's vendor only has it as a dev stand-in.
if (!interface_exists(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class)) {
    eval('namespace Symfony\\Component\\EventDispatcher; interface EventSubscriberInterface { public static function getSubscribedEvents(): array; }');
}

$forbiddenNamespaces = ['OxidEsales\\GraphQL\\', 'TheCodingMachine\\GraphQLite\\', 'GraphQL\\'];
$refused = [];
spl_autoload_register(static function (string $symbol) use ($forbiddenNamespaces, &$refused): void {
    foreach ($forbiddenNamespaces as $namespace) {
        if (str_starts_with($symbol, $namespace)) {
            $refused[] = $symbol;
        }
    }
}, true, true);

/**
 * Classes that extend a class of the shop itself (the virtual-parent chain, admin controllers) cannot be declared
 * without the shop's class tree; the shop integration suite loads those.
 */
$needsShop = static fn (string $source): bool => preg_match('/^(abstract |final )?class \w+ extends [\w\\\\]*\\\\?(AdminDetailsController|AdminController|FrontendController|\\\\Eshop|Eshop\\\\)/m', $source) === 1
    || str_contains($source, 'namespace OxidEsales\\PaymentBase\\Eshop\\');

$current = '';
register_shutdown_function(static function () use (&$current): void {
    if ($current !== '' && error_get_last() !== null) {
        echo "WHILE LOADING $current\n";
    }
});

$declared = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || str_contains($path, '/src/GraphQL/') || str_contains($path, '/src/Composer/')) {
        continue;
    }
    $source = (string) file_get_contents($path);
    if ($needsShop($source) || !preg_match('/^(?:abstract |final |readonly )*(class|interface|trait|enum) (\w+)/m', $source, $kind)) {
        continue;
    }
    $relative = substr($path, strlen($moduleRoot . '/src/'), -4);
    $current = 'OxidEsales\\PaymentBase\\' . str_replace('/', '\\', $relative);
    if (!class_exists($current) && !interface_exists($current) && !trait_exists($current) && !enum_exists($current)) {
        echo "NOT DECLARED: $current\n";
        exit(1);
    }
    ++$declared;
}

$current = '';
foreach ([new InvalidClientPublicKey('x'), new UnknownClientConfigProvider('x')] as $exception) {
    if (!$exception instanceof Throwable) {
        exit(1);
    }
}
if ($refused !== []) {
    echo 'ASKED FOR A GRAPHQL CLASS: ' . implode(', ', array_unique($refused)) . "\n";
    exit(1);
}

echo "OK $declared\n";
