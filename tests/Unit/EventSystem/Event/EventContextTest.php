<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class EventContextTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $context = new EventContext();

        $this->assertInstanceOf(EventContextInterface::class, $context);
    }

    public function testConstructor_WithEmptyArray_CreatesEmptyContext(): void
    {
        $context = new EventContext();

        $this->assertEquals([], $context->all());
    }

    public function testConstructor_WithInitialData_StoresData(): void
    {
        $data = ['userId' => 'user_123', 'basket' => new \stdClass()];
        $context = new EventContext($data);

        $this->assertEquals($data, $context->all());
    }

    public function testSet_StoresValue(): void
    {
        $context = new EventContext();

        $context->set('userId', 'user_456');

        $this->assertEquals('user_456', $context->get('userId'));
    }

    public function testSet_OverwritesExistingValue(): void
    {
        $context = new EventContext(['userId' => 'old_value']);

        $context->set('userId', 'new_value');

        $this->assertEquals('new_value', $context->get('userId'));
    }

    public function testGet_ReturnsStoredValue(): void
    {
        $context = new EventContext(['key' => 'value']);

        $result = $context->get('key');

        $this->assertEquals('value', $result);
    }

    public function testGet_WithNonExistentKey_ReturnsNull(): void
    {
        $context = new EventContext();

        $result = $context->get('nonexistent');

        $this->assertNull($result);
    }

    public function testGet_WithNonExistentKeyAndDefault_ReturnsDefault(): void
    {
        $context = new EventContext();

        $result = $context->get('nonexistent', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function testHas_WithExistingKey_ReturnsTrue(): void
    {
        $context = new EventContext(['key' => 'value']);

        $this->assertTrue($context->has('key'));
    }

    public function testHas_WithNonExistentKey_ReturnsFalse(): void
    {
        $context = new EventContext();

        $this->assertFalse($context->has('nonexistent'));
    }

    public function testAll_ReturnsAllData(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3];
        $context = new EventContext($data);

        $this->assertEquals($data, $context->all());
    }

    public function testGetBasket_ReturnsBasketObject(): void
    {
        $basket = new \stdClass();
        $basket->total = 100.00;
        $context = new EventContext(['basket' => $basket]);

        $result = $context->getBasket();

        $this->assertSame($basket, $result);
    }

    public function testGetBasket_WhenNotSet_ReturnsNull(): void
    {
        $context = new EventContext();

        $this->assertNull($context->getBasket());
    }

    public function testGetUser_ReturnsUserObject(): void
    {
        $user = new \stdClass();
        $user->id = 'user_123';
        $context = new EventContext(['user' => $user]);

        $result = $context->getUser();

        $this->assertSame($user, $result);
    }

    public function testGetUser_WhenNotSet_ReturnsNull(): void
    {
        $context = new EventContext();

        $this->assertNull($context->getUser());
    }

    public function testGetOrderId_ReturnsOrderId(): void
    {
        $context = new EventContext(['orderId' => 'order_789']);

        $result = $context->getOrderId();

        $this->assertEquals('order_789', $result);
    }

    public function testGetOrderId_WhenNotSet_ReturnsNull(): void
    {
        $context = new EventContext();

        $this->assertNull($context->getOrderId());
    }

    public function testSetContract_StoresContract(): void
    {
        $context = new EventContext();
        $contract = $this->createMock(PaymentContractInterface::class);

        $context->setContract($contract);

        $this->assertSame($contract, $context->getContract());
    }

    public function testGetContract_WhenNotSet_ReturnsNull(): void
    {
        $context = new EventContext();

        $this->assertNull($context->getContract());
    }

    public function testHasContract_WhenContractSet_ReturnsTrue(): void
    {
        $context = new EventContext();
        $contract = $this->createMock(PaymentContractInterface::class);

        $context->setContract($contract);

        $this->assertTrue($context->hasContract());
    }

    public function testHasContract_WhenContractNotSet_ReturnsFalse(): void
    {
        $context = new EventContext();

        $this->assertFalse($context->hasContract());
    }

    public function testContextSupportsMultipleDataTypes(): void
    {
        $context = new EventContext();

        $context->set('string', 'text');
        $context->set('integer', 123);
        $context->set('float', 45.67);
        $context->set('boolean', true);
        $context->set('array', [1, 2, 3]);
        $context->set('object', new \stdClass());

        $this->assertEquals('text', $context->get('string'));
        $this->assertEquals(123, $context->get('integer'));
        $this->assertEquals(45.67, $context->get('float'));
        $this->assertTrue($context->get('boolean'));
        $this->assertEquals([1, 2, 3], $context->get('array'));
        $this->assertInstanceOf(\stdClass::class, $context->get('object'));
    }
}
