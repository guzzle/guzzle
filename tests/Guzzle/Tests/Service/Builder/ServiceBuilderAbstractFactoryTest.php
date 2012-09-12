<?php

namespace Guzzle\Tests\Service\Builder;

use Guzzle\Service\Builder\ServiceBuilderAbstractFactory;

/**
 * @covers Guzzle\Service\Builder\ServiceBuilderAbstractFactory
 */
class ServiceBuilderAbstractFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $jsonFile;

    public function setup()
    {
        $this->jsonFile = __DIR__ . '/../../TestData/services/json1.json';
    }

    public function testFactoryDelegatesToConcreteFactories()
    {
        $factory = new ServiceBuilderAbstractFactory();
        $this->assertInstanceOf('Guzzle\Service\Builder\ServiceBuilder', $factory->build($this->jsonFile));
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     * @expectedExceptionMessage Must pass the name of a .js or .json file or array
     */
    public function testThrowsExceptionWhenInvalidFileExtensionIsPassed()
    {
        $factory = new ServiceBuilderAbstractFactory();
        $factory->build(__FILE__);
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     * @expectedExceptionMessage Must pass the name of a .js or .json file or array
     */
    public function testThrowsExceptionWhenUnknownTypeIsPassed()
    {
        $factory = new ServiceBuilderAbstractFactory();
        $factory->build(new \stdClass());
    }

    public function configProvider()
    {
        $foo = array(
            'extends' => 'bar',
            'class'   => 'stdClass',
            'params'  => array('a' => 'test', 'b' => '456')
        );

        return array(
            array(
                // Does not extend the existing `foo` service but overwrites it
                array(
                    'services' => array(
                        'foo' => $foo,
                        'bar' => array('params' => array('baz' => '123'))
                    )
                ),
                array(
                    'services' => array(
                        'foo' => array('class' => 'Baz')
                    )
                ),
                array(
                    'services' => array(
                        'foo' => array('class' => 'Baz'),
                        'bar' => array('params' => array('baz' => '123'))
                    )
                )
            ),
            array(
                // Extends the existing `foo` service
                array(
                    'services' => array(
                        'foo' => $foo,
                        'bar' => array('params' => array('baz' => '123'))
                    )
                ),
                array(
                    'services' => array(
                        'foo' => array(
                            'extends' => 'foo',
                            'params'  => array('b' => '123', 'c' => 'def')
                        )
                    )
                ),
                array(
                    'services' => array(
                        'foo' => array(
                            'extends' => 'bar',
                            'class'   => 'stdClass',
                            'params'  => array('a' => 'test', 'b' => '123', 'c' => 'def')
                        ),
                        'bar' => array('params' => array('baz' => '123'))
                    )
                )
            )
        );
    }

    /**
     * @dataProvider configProvider
     */
    public function testCombinesConfigs($a, $b, $c)
    {
        $this->assertEquals($c, ServiceBuilderAbstractFactory::combineConfigs($a, $b));
    }
}
