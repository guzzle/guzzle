<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ServiceDescriptionAbstractFactory;

/**
 * @covers Guzzle\Service\Description\ServiceDescriptionAbstractFactory
 */
class ServiceDescriptionAbstractFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testFactoryDelegatesToConcreteFactories()
    {
        $factory = new ServiceDescriptionAbstractFactory();
        $this->assertInstanceOf(
            'Guzzle\Service\Description\ServiceDescription',
            $factory->build(__DIR__ . '/../../TestData/test_service.json')
        );
    }

    /**
     * @expectedException Guzzle\Service\Exception\DescriptionBuilderException
     */
    public function testFactoryEnsuresItCanHandleTheTypeOfFileOrArray()
    {
        $factory = new ServiceDescriptionAbstractFactory();
        $factory->build('jarJarBinks');
    }
}
