<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Runs in a fresh PHP process in which the given namespaces cannot be loaded (an autoloader in front of everything
 * refuses them, whatever is installed), and seals the sealed-client-config vector (docs/payments-keys.md) the way the
 * module does in production: with the primitives SealPrimitivesFactory picks. Proves that the sealed client config
 * works with and without GraphQL and with and without the sealed-channel module (R5 of SEAL-1).
 *
 *   php seal-with-and-without.php "OxidEsales\\GraphQL\\,TheCodingMachine\\GraphQLite\\"   (no GraphQL)
 *   php seal-with-and-without.php "OxidEsales\\SealedChannel\\"                             (no sealed channel)
 *
 * Prints "OK <primitives class> installed=<0|1>" on success (installed: the module is visible to the process), or the first failure and exit code 1. SHOP_AUTOLOAD names the autoloader
 * of a shop (default /var/www/vendor/autoload.php, where the sealed-channel module is installed when it is); without
 * one the module's own vendor directory is used.
 */

declare(strict_types=1);

$blocked = array_values(array_filter(explode(',', $argv[1] ?? '')));

$moduleRoot = dirname(__DIR__, 4);
$shopAutoload = getenv('SHOP_AUTOLOAD') ?: '/var/www/vendor/autoload.php';
require is_file($shopAutoload) ? $shopAutoload : $moduleRoot . '/vendor/autoload.php';
require_once $moduleRoot . '/vendor/autoload.php';
// The real autoloaders are put behind a filter: a blocked class is simply not found, exactly as on a shop without the
// package (a probe like class_exists answers false; a real use is a fatal "class not found" that fails this script).
$loaders = spl_autoload_functions();
foreach ($loaders as $loader) {
    spl_autoload_unregister($loader);
}
spl_autoload_register(static function (string $class) use ($blocked, $loaders): void {
    foreach ($blocked as $prefix) {
        if (str_starts_with($class, $prefix)) {
            return;
        }
    }
    foreach ($loaders as $loader) {
        $loader($class);
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }
    }
});

foreach (['EcKeys', 'FixedSealInputs'] as $support) {
    require_once $moduleRoot . '/tests/Unit/ClientConfig/' . $support . '.php';
}

use OxidEsales\PaymentBase\ClientConfig\ClientConfigSealer;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealPrimitivesInterface;
use OxidEsales\PaymentBase\ClientConfig\SealPrimitivesFactory;
use OxidEsales\PaymentBase\Tests\Unit\ClientConfig\EcKeys;
use OxidEsales\PaymentBase\Tests\Unit\ClientConfig\FixedSealInputs;

try {
    $vector = json_decode(
        (string) file_get_contents($moduleRoot . '/tests/Unit/ClientConfig/fixtures/sealed-config-vector.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $primitives = SealPrimitivesFactory::create();
    assert($primitives instanceof SealPrimitivesInterface);
    $sealer = new ClientConfigSealer(
        new FixedSealInputs(
            EcKeys::privateKeyFromJwk($vector['serverPrivateKeyJwk']),
            [32 => EcKeys::b64urlDecode($vector['salt']), 12 => EcKeys::b64urlDecode($vector['iv'])],
            $vector['issuedAt']
        )
    );
    $envelope = $sealer->seal(
        $vector['provider'],
        $vector['tokenId'],
        $vector['clientPublicKey'],
        ['clientId' => 'VECTOR-NOT-A-REAL-KEY']
    );
    if (get_object_vars($envelope) !== $vector['envelope']) {
        throw new RuntimeException('The envelope differs from the shared vector');
    }
    echo 'OK ', $primitives::class, ' installed=', SealPrimitivesFactory::sealedChannelAvailable() ? '1' : '0', "\n";
} catch (Throwable $failure) {
    echo 'FAILED ', $failure->getMessage(), "\n";
    exit(1);
}
