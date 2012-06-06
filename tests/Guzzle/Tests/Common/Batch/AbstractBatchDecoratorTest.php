<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Batch\Batch;

/**
 * @covers Guzzle\Common\Batch\AbstractBatchDecorator
 */
class AbstractBatchDecoratorTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testProxiesToWrappedObject()
    {
        $batch = new Batch(
            $this->getMock('Guzzle\Common\Batch\BatchTransferInterface'),
            $this->getMock('Guzzle\Common\Batch\BatchDivisorInterface')
        );

        $decoratorA = $this->getMockBuilder('Guzzle\Common\Batch\AbstractBatchDecorator')
            ->setConstructorArgs(array($batch))
            ->getMockForAbstractClass();

        $decoratorB = $this->getMockBuilder('Guzzle\Common\Batch\AbstractBatchDecorator')
            ->setConstructorArgs(array($decoratorA))
            ->getMockForAbstractClass();

        $decoratorA->add('foo');
        $this->assertEquals(1, count($decoratorB));
        $this->assertEquals(1, count($batch));
        $this->assertEquals(array($decoratorB, $decoratorA), $decoratorB->getDecorators());
        $this->assertEquals(array(), $decoratorB->flush());
    }
}
