<?php

namespace Guzzle\Tests\Service\Builder;

use Guzzle\Service\Builder\ArrayServiceBuilderFactory;
use Guzzle\Service\Builder\ServiceBuilder;
use Guzzle\Service\Builder\JsonServiceBuilderFactory;

/**
 * @covers Guzzle\Service\Builder\JsonServiceBuilderFactory
 * @covers Guzzle\Service\Builder\ArrayServiceBuilderFactory
 */
class JsonServiceBuilderFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testBuildsServiceBuilders()
    {
        $j = new JsonServiceBuilderFactory(new ArrayServiceBuilderFactory());
        $file = __DIR__ . '/../../TestData/services/json1.json';

        // Initial build
        $builder = $j->build($file);
        // Build it again, get a similar result using the same JsonLoader
        $this->assertEquals($builder, $j->build($file));

        // Ensure that services were parsed
        $this->assertTrue(isset($builder['mock']));
        $this->assertTrue(isset($builder['abstract']));
        $this->assertTrue(isset($builder['foo']));
        $this->assertFalse(isset($builder['jimmy']));
    }

    public function testServicesCanBeExtendedWithIncludes()
    {
        $data = array(
            'services' => array(
                'foo' => array(
                    'class'  => 'stdClass',
                    'params' => array('baz' => 'bar')
                ),
                'bar' => array(
                    'extends' => 'foo',
                    'params'  => array(
                        'test' => '123',
                        'ext'  => 'abc'
                    )
                )
            )
        );
        $file1 = tempnam(sys_get_temp_dir(), 'service_1') . '.json';
        file_put_contents($file1, json_encode($data));

        $data = array(
            'includes' => array($file1),
            'services' => array(
                'bar' => array(
                    'extends' => 'bar',
                    'params'  => array(
                        'test' => '456'
                    )
                )
            )
        );
        $file2 = tempnam(sys_get_temp_dir(), 'service_2') . '.json';
        file_put_contents($file2, json_encode($data));

        $builder = ServiceBuilder::factory($file2);
        unlink($file1);
        unlink($file2);

        $this->assertEquals(array(
            'bar' => array(
                'class'   => 'stdClass',
                'extends' => 'foo',
                'params'  => array(
                    'test' => '456',
                    'baz'  => 'bar',
                    'ext'  => 'abc'
                )
            ),
            'foo' => array(
                'class'  => 'stdClass',
                'params' => array('baz' => 'bar')
            )
        ), $this->readAttribute($builder, 'builderConfig'));
    }
}
