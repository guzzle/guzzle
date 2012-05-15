<?php

namespace Guzzle\Tests\Service\Builder;

use Guzzle\Service\Builder\ArrayServiceBuilderFactory;

/**
 * @covers Guzzle\Service\Builder\ArrayServiceBuilderFactory
 */
class ArrayServiceBuilderFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testBuildsServiceBuilders()
    {
        $arrayFactory = new ArrayServiceBuilderFactory();

        $data = array(
            'services' => array(
                'abstract' => array(
                    'params' => array(
                        'access_key' => 'xyz',
                        'secret' => 'abc',
                    ),
                ),
                'foo' => array(
                    'extends' => 'abstract',
                    'params' => array(
                        'baz' => 'bar',
                    ),
                ),
                'mock' => array(
                    'extends' => 'abstract',
                    'params' => array(
                        'username' => 'foo',
                        'password' => 'baz',
                        'subdomain' => 'bar',
                    )
                )
            )
        );

        $builder = $arrayFactory->build($data);

        // Ensure that services were parsed
        $this->assertTrue(isset($builder['mock']));
        $this->assertTrue(isset($builder['abstract']));
        $this->assertTrue(isset($builder['foo']));
        $this->assertFalse(isset($builder['jimmy']));
    }

    /**
     * @expectedException Guzzle\Service\Exception\ServiceNotFoundException
     * @expectedExceptionMessage foo is trying to extend a non-existent service: abstract
     */
    public function testThrowsExceptionWhenExtendingNonExistentService()
    {
        $arrayFactory = new ArrayServiceBuilderFactory();

        $data = array(
            'services' => array(
                'foo' => array(
                    'extends' => 'abstract'
                )
            )
        );

        $builder = $arrayFactory->build($data);
    }

    public function testAllowsGlobalParameterOverrides()
    {
        $arrayFactory = new ArrayServiceBuilderFactory();

        $data = array(
            'services' => array(
                'foo' => array(
                    'params' => array(
                        'foo' => 'baz',
                        'bar' => 'boo'
                    )
                )
            )
        );

        $builder = $arrayFactory->build($data, array(
            'bar' => 'jar',
            'far' => 'car'
        ));

        $compiled = json_decode($builder->serialize(), true);
        $this->assertEquals(array(
            'foo' => 'baz',
            'bar' => 'jar',
            'far' => 'car'
        ), $compiled['foo']['params']);
    }
}
