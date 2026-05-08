<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem;

use OxidEsales\PaymentBase\EventSystem\EventListenerProviderInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for EventListenerProviderInterface.
 *
 * These tests verify the interface contract exists with correct method signatures.
 */
class EventListenerProviderInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(EventListenerProviderInterface::class));
    }

    public function testInterfaceDefinesGetListenersForEvent(): void
    {
        $reflection = new ReflectionClass(EventListenerProviderInterface::class);

        $this->assertTrue($reflection->hasMethod('getListenersForEvent'));

        $method = $reflection->getMethod('getListenersForEvent');
        $this->assertTrue($method->isPublic());

        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('eventClass', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()?->getName());
    }

    public function testInterfaceDefinesAddListener(): void
    {
        $reflection = new ReflectionClass(EventListenerProviderInterface::class);

        $this->assertTrue($reflection->hasMethod('addListener'));

        $method = $reflection->getMethod('addListener');
        $this->assertTrue($method->isPublic());

        $parameters = $method->getParameters();
        $this->assertCount(3, $parameters);

        $this->assertEquals('eventClass', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()?->getName());

        $this->assertEquals('listener', $parameters[1]->getName());
        $this->assertEquals('callable', $parameters[1]->getType()?->getName());

        $this->assertEquals('priority', $parameters[2]->getName());
        $this->assertEquals('int', $parameters[2]->getType()?->getName());
        $this->assertTrue($parameters[2]->isDefaultValueAvailable());
        $this->assertEquals(0, $parameters[2]->getDefaultValue());
    }

    public function testGetListenersForEventReturnType(): void
    {
        $reflection = new ReflectionClass(EventListenerProviderInterface::class);
        $method = $reflection->getMethod('getListenersForEvent');

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testAddListenerReturnType(): void
    {
        $reflection = new ReflectionClass(EventListenerProviderInterface::class);
        $method = $reflection->getMethod('addListener');

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('void', $returnType->getName());
    }
}
