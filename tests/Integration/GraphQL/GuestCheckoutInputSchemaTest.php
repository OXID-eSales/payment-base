<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Schema;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\GraphQL\DataType\GuestCheckoutInputFactory;
use OxidEsales\PaymentBase\Tests\Integration\Support\FixedClassFinder;
use OxidEsales\PaymentBase\Tests\Integration\Support\GuestInputProbe;
use PHPUnit\Framework\Attributes\Group;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TheCodingMachine\GraphQLite\SchemaFactory;

/**
 * Sprint 140b / B2: the `guest` input every `<provider>CheckoutStart` shares,
 * built by GraphQLite from payment-base's factories into a real schema and run
 * through real argument resolution. The mutation here is a probe standing in
 * for a provider's; what is proven is the input types and the error shape.
 *
 * Needs graphql-base (GraphQLite); skipped where it is not installed.
 */
#[Group('integration')]
final class GuestCheckoutInputSchemaTest extends IntegrationTestCase
{
    private const VALID_ADDRESS = '{firstName: "Gerda", lastName: "Guest", street: "Grunewaldstr.", streetNumber: "1", zipCode: "79098", city: "%s", countryId: "a7c40f631fc920687.20179984", company: "Acme"}';

    private GuestInputProbe $probe;

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(SchemaFactory::class)) {
            self::markTestSkipped('graphql-base / GraphQLite is not installed');
        }
        // Required by path: a named class implementing kcs/class-finder's interface is a fatal
        // "Interface not found" on a shop without GraphQLite, so it is loaded only after the guard.
        require_once __DIR__ . '/../Support/FixedClassFinder.php';
        require_once __DIR__ . '/../Support/GuestInputProbe.php';
        $this->probe = new GuestInputProbe();
    }

    public function testTheGuestInputTypesCarryExactlyTheAgreedFields(): void
    {
        $schema = $this->schema();
        $schema->assertValid();

        $guest = $schema->getType('GuestCheckoutInput');
        self::assertInstanceOf(InputObjectType::class, $guest);
        self::assertSame(['email', 'invoiceAddress', 'deliveryAddress'], array_keys($guest->getFields()));
        self::assertSame('String!', (string) $guest->getField('email')->getType());
        self::assertSame('GuestAddressInput!', (string) $guest->getField('invoiceAddress')->getType());
        self::assertSame('GuestAddressInput', (string) $guest->getField('deliveryAddress')->getType());

        $address = $schema->getType('GuestAddressInput');
        self::assertInstanceOf(InputObjectType::class, $address);
        self::assertSame(
            [
                'firstName', 'lastName', 'street', 'streetNumber', 'zipCode', 'city', 'countryId',
                'salutation', 'company', 'additionalInfo', 'stateId', 'phone',
            ],
            array_keys($address->getFields())
        );
        self::assertSame('String!', (string) $address->getField('firstName')->getType());
        self::assertSame('ID!', (string) $address->getField('countryId')->getType());
        self::assertSame('String', (string) $address->getField('company')->getType(), 'optional fields are nullable');
        self::assertSame('ID', (string) $address->getField('stateId')->getType());
    }

    public function testGuestArgument_ValidInput_ReachesTheMutationAsGuestDetails(): void
    {
        $result = $this->execute($this->probeQuery('guest@example.com', 'Freiburg'));

        self::assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        self::assertTrue($result['data']['guestProbe']);
        self::assertSame('guest@example.com', $this->probe->received?->email);
        self::assertSame('a7c40f631fc920687.20179984', $this->probe->received->invoiceAddress->countryId);
        self::assertNull($this->probe->received->deliveryAddress);
    }

    public function testGuestArgument_InvalidEmail_IsRefusedWithTheStableCodeAndNoValueEcho(): void
    {
        $result = $this->execute($this->probeQuery('not-an-email', 'Freiburg'));

        self::assertSame('guest_details_required', $result['errors'][0]['extensions']['errorCode']);
        self::assertStringContainsString('email', $result['errors'][0]['message']);
        self::assertStringNotContainsString('not-an-email', $result['errors'][0]['message']);
        self::assertNull($this->probe->received, 'the mutation body never ran');
    }

    public function testGuestArgument_EmptyRequiredAddressField_IsRefusedNamingTheField(): void
    {
        $result = $this->execute($this->probeQuery('guest@example.com', ''));

        self::assertSame('guest_details_required', $result['errors'][0]['extensions']['errorCode']);
        self::assertStringContainsString('city', $result['errors'][0]['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(string $query): array
    {
        return GraphQL::executeQuery($this->schema(), $query)->toArray();
    }

    private function probeQuery(string $email, string $city): string
    {
        return sprintf(
            'mutation { guestProbe(guest: {email: "%s", invoiceAddress: ' . self::VALID_ADDRESS . '}) }',
            $email,
            $city
        );
    }

    private function schema(): Schema
    {
        $probe = $this->probe;
        $container = new class ($probe) implements ContainerInterface {
            public function __construct(private readonly GuestInputProbe $probe)
            {
            }

            public function get(string $id): object
            {
                return $this->probe;
            }

            public function has(string $id): bool
            {
                return $id === GuestInputProbe::class;
            }
        };

        $factory = new SchemaFactory(new Psr16Cache(new ArrayAdapter()), $container);
        $factory->setFinder(new FixedClassFinder([GuestInputProbe::class, GuestCheckoutInputFactory::class]));
        $factory->addControllerNamespace('OxidEsales\\PaymentBase\\Tests\\Integration\\Support');
        $factory->addTypeNamespace('OxidEsales\\PaymentBase\\GraphQL\\DataType');

        return $factory->createSchema();
    }
}
