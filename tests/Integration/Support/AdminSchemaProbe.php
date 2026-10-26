<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

use GraphQL\Utils\SchemaPrinter;
use Kcs\ClassFinder\Finder\Psr4Finder;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\GraphQL\Admin\Shared\Service\NamespaceMapper as AdminNamespaceMapper;
use OxidEsales\GraphQL\Base\Framework\AggregatedFinder;
use OxidEsales\GraphQL\Base\Service\NamespaceMapper as BaseNamespaceMapper;
use OxidEsales\PaymentBase\GraphQL\Service\NamespaceMapper as PaymentBaseNamespaceMapper;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use TheCodingMachine\GraphQLite\Security\AuthenticationServiceInterface;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\SchemaFactory;

/**
 * The GraphQL schema as graphql-base builds it from the namespace mappers (the same finder, the same factory),
 * for the modules a test names, rendered as SDL. graphql-base's own SchemaFactory is a private service, and the
 * schema of the running shop is cached; building a fresh one here makes the test independent of both. Needs
 * oxid-admin-graphql.
 */
final class AdminSchemaProbe
{
    public static function sdl(): string
    {
        $finder = new AggregatedFinder();
        $factory = new SchemaFactory(self::noCache(), self::container());
        foreach ([new BaseNamespaceMapper(), new AdminNamespaceMapper(), new PaymentBaseNamespaceMapper(storefrontInstalled: false)] as $mapper) {
            foreach ($mapper->getControllerNamespaceMapping() as $namespace => $path) {
                $finder->addFinder(new Psr4Finder($namespace, $path));
                $factory->addControllerNameSpace($namespace);
            }
            foreach ($mapper->getTypeNamespaceMapping() as $namespace => $path) {
                $finder->addFinder(new Psr4Finder($namespace, $path));
                $factory->addTypeNameSpace($namespace);
            }
        }
        $factory->setFinder($finder)
            ->setAuthenticationService(new class implements AuthenticationServiceInterface {
                public function isLogged(): bool
                {
                    return true;
                }

                public function getUser(): ?object
                {
                    return null;
                }
            })
            ->setAuthorizationService(new class implements AuthorizationServiceInterface {
                public function isAllowed(string $right, $subject = null): bool
                {
                    return true;
                }
            });

        return SchemaPrinter::doPrint($factory->createSchema());
    }

    private static function container(): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = ContainerFactory::getInstance()->getContainer();

        return $container;
    }

    private static function noCache(): CacheInterface
    {
        return new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\NullAdapter());
    }
}
