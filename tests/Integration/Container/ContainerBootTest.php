<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Container;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * VR-25 D3: with every collaborator required, a service that is wired short of one fails when the container
 * builds it - so build every public one. A missing argument shows up here, not in the first request that needs it.
 */
// Resolving every public service builds shop state (session, admin mode, config) that later tests in this
// process would inherit - order mails then rendered instead of deferred, the language views of another shop.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContainerBootTest extends TestCase
{
    #[DataProvider('publicServiceIds')]
    public function testThePublicServiceResolves(string $id): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        try {
            $service = $container->get($id);
        } catch (Throwable $failure) {
            self::fail(sprintf('%s does not resolve: %s', $id, $failure::class . ' ' . $failure->getMessage()));
        }

        self::assertNotNull($service, $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicServiceIds(): iterable
    {
        $definitions = Yaml::parseFile(dirname(__DIR__, 3) . '/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $services = $definitions['services'] ?? [];
        self::assertIsArray($services);

        foreach ($services as $id => $definition) {
            if (!is_string($id) || !is_array($definition) || ($definition['public'] ?? false) !== true) {
                continue;
            }
            if ($definition === [] || $definition instanceof TaggedValue) {
                continue;
            }
            yield $id => [$id];
        }
    }
}
