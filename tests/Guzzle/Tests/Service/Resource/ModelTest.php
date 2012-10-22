<?php

namespace Guzzle\Tests\Service\Resource;

use Guzzle\Service\Resource\Model;
use Guzzle\Service\Description\Parameter;
use Guzzle\Common\Collection;

/**
 * @covers Guzzle\Service\Resource\Model
 */
class ModelTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testOwnsStructure()
    {
        $param = new Parameter(array('type' => 'object'));
        $model = new Model(array('foo' => 'bar'), $param);
        $this->assertSame($param, $model->getStructure());
        $this->assertEquals('bar', $model->get('foo'));
        $this->assertEquals('bar', $model['foo']);
    }

    public function testRetrievesNestedKeysUsingPath()
    {
        $data = array(
            'foo' => 'bar',
            'baz' => array(
                'mesa' => array(
                    'jar' => 'jar'
                )
            )
        );
        $param = new Parameter(array('type' => 'object'));
        $model = new Model($data, $param);
        $this->assertSame($param, $model->getStructure());
        $this->assertEquals('bar', $model->getPath('foo'));
        $this->assertEquals('jar', $model->getPath('baz/mesa/jar'));
        $this->assertNull($model->getPath('wewewf'));
        $this->assertNull($model->getPath('baz/mesa/jar/jar'));
        $this->assertSame($data, $model->toArray());
    }

    public function testCanBeUsedWithoutStructure()
    {
        $model = new Model(array(
            'Foo' => 'baz',
            'Bar' => array(
                'Boo' => 'Bam'
            )
        ));
        $transform = function ($key, $value) {
            return ($value && is_array($value)) ? new Collection($value) : $value;
        };
        $model = $model->map($transform);
        $this->assertInstanceOf('Guzzle\Common\Collection', $model->getPath('Bar'));
    }

    public function testFalseyKeysStillDescend()
    {
        $model = new Model(array(
            '0' => array(
                'a' => 'jar'
            ),
            1 => 'other'
        ));
        $this->assertEquals('jar', $model->getPath('0/a'));
        $this->assertEquals('other', $model->getPath('1'));
    }

    public function testAllowsFiltering()
    {
        $model = new Model(array(
            'Foo' => 'baz',
            'Bar' => 'a'
        ));
        $model = $model->filter(function ($i, $v) {
            return $v[0] == 'a';
        });
        $this->assertEquals(array('Bar' => 'a'), $model->toArray());
    }
}
