<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ApiParam;

/**
 * @covers Guzzle\Service\Description\ApiParam
 */
class ApiParamTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $data = array(
        'name'         => 'foo',
        'type'         => 'bar',
        'type_args'    => null,
        'required'     => true,
        'default'      => '123',
        'doc'          => '456',
        'min_length'   => 2,
        'max_length'   => 5,
        'location'     => 'body',
        'location_key' => 'foo',
        'static'       => 'static!',
        'prepend'      => 'before.',
        'append'       => '.after',
        'filters'      => array('trim', 'json_encode'),
        'structure'    => array()
    );

    public function testCreatesParamFromArray()
    {
        $p = new ApiParam($this->data);
        $this->assertEquals('foo', $p->getName());
        $this->assertEquals('bar', $p->getType());
        $this->assertEquals(true, $p->getRequired());
        $this->assertEquals('123', $p->getDefault());
        $this->assertEquals('456', $p->getDoc());
        $this->assertEquals(2, $p->getMinLength());
        $this->assertEquals(5, $p->getMaxLength());
        $this->assertEquals('body', $p->getLocation());
        $this->assertEquals('static!', $p->getStatic());
        $this->assertEquals('before.', $p->getPrepend());
        $this->assertEquals('.after', $p->getAppend());
        $this->assertEquals(array('trim', 'json_encode'), $p->getFilters());
    }

    public function testCanConvertToArray()
    {
        $p = new ApiParam($this->data);
        $this->assertEquals($this->data, $p->toArray());
    }

    public function testUsesStatic()
    {
        $d = $this->data;
        $d['static'] = 'foo';
        $p = new ApiParam($d);
        $this->assertEquals('foo', $p->getValue('bar'));
    }

    public function testUsesDefault()
    {
        $d = $this->data;
        $d['default'] = 'foo';
        $d['static'] = null;
        $p = new ApiParam($d);
        $this->assertEquals('foo', $p->getValue(null));
    }

    public function testReturnsYourValue()
    {
        $d = $this->data;
        $d['static'] = null;
        $p = new ApiParam($d);
        $this->assertEquals('foo', $p->getValue('foo'));
    }

    public function testFiltersValues()
    {
        $d = $this->data;
        $d['static'] = null;
        $d['filters'] = 'strtoupper';
        $p = new ApiParam($d);
        $this->assertEquals('FOO', $p->filter('foo'));
    }

    public function testUsesArrayByDefaultForFilters()
    {
        $d = $this->data;
        $d['filters'] = null;
        $p = new ApiParam($d);
        $this->assertEquals(array(), $p->getFilters());
    }

    public function testParsesLocationValue()
    {
        $p = new ApiParam(array(
            'location' => 'foo:bar'
        ));
        $this->assertEquals('foo', $p->getLocation());
        $this->assertEquals('bar', $p->getLocationKey());
    }

    public function testParsesTypeValues()
    {
        $p = new ApiParam(array(
            'type' => 'foo:baz,bar,boo'
        ));
        $this->assertEquals('foo', $p->getType());
        $this->assertEquals(array('baz,bar,boo'), $p->getTypeArgs());
    }

    public function testAllowsExplicitTypeArgs()
    {
        $p = new ApiParam(array(
            'type'      => 'foo',
            'type_args' => array('baz', 'bar', 'boo')
        ));
        $this->assertEquals('foo', $p->getType());
        $this->assertEquals(array('baz', 'bar', 'boo'), $p->getTypeArgs());

        $p = new ApiParam(array(
            'type'      => 'foo',
            'type_args' => 'baz'
        ));
        $this->assertEquals('foo', $p->getType());
        $this->assertEquals(array('baz'), $p->getTypeArgs());
    }

    public function testAllowsDotNotationForFiltersClasses()
    {
        $p = new ApiParam(array(
            'filters' => array('Mesa\JarJar::binks', 'Yousa\NoJarJar::binks', 'Foo\Baz::bar')
        ));
        $this->assertEquals(array(
            'Mesa\JarJar::binks',
            'Yousa\NoJarJar::binks',
            'Foo\\Baz::bar'
        ), $p->getFilters());
    }

    public function testCanBuildUpParams()
    {
        $p = new ApiParam(array());
        $p->setName('foo')
            ->setAppend('a')
            ->setDefault('b')
            ->setDoc('c')
            ->setFilters(array('d'))
            ->setLocation('e')
            ->setLocationKey('f')
            ->setMaxLength(2)
            ->setMinLength(1)
            ->setPrepend('g')
            ->setRequired(true)
            ->setStatic('h')
            ->setType('i')
            ->setTypeArgs(array('j'));

        $p->addFilter('foo');

        $this->assertEquals('foo', $p->getName());
        $this->assertEquals('a', $p->getAppend());
        $this->assertEquals('b', $p->getDefault());
        $this->assertEquals('c', $p->getDoc());
        $this->assertEquals(array('d', 'foo'), $p->getFilters());
        $this->assertEquals('e', $p->getLocation());
        $this->assertEquals('f', $p->getLocationKey());
        $this->assertEquals(2, $p->getMaxLength());
        $this->assertEquals(1, $p->getMinLength());
        $this->assertEquals('g', $p->getPrepend());
        $this->assertEquals(true, $p->getRequired());
        $this->assertEquals('h', $p->getStatic());
        $this->assertEquals('i', $p->getType());
        $this->assertEquals(array('j'), $p->getTypeArgs());
    }

    public function testAllowsNestedStructures()
    {
        $command = $this->getServiceBuilder()->get('mock')->getCommand('mock_command')->getApiCommand();
        $param = new ApiParam(array(
            'parent'    => $command,
            'name'      => 'foo',
            'type'      => 'array',
            'location'  => 'query',
            'structure' => array(
                'foo' => array(
                    'type'      => 'array',
                    'required'  => true,
                    'structure' => array(
                        'baz' => array(
                            'name' => 'baz',
                            'type' => 'bool',
                        )
                    )
                ),
                array(
                    'name'    => 'bar',
                    'default' => '123'
                )
            )
        ));

        $this->assertSame($command, $param->getParent());
        $this->assertNotEmpty($param->getStructure());
        $this->assertInstanceOf('Guzzle\Service\Description\ApiParam', $param->getStructure('foo'));
        $this->assertSame($param, $param->getStructure('foo')->getParent());
        $this->assertSame($param->getStructure('foo'), $param->getStructure('foo')->getStructure('baz')->getParent());
        $this->assertInstanceOf('Guzzle\Service\Description\ApiParam', $param->getStructure('bar'));
        $this->assertSame($param, $param->getStructure('bar')->getParent());

        $array = $param->toArray();
        $this->assertInternalType('array', $array['structure']);
        $this->assertArrayHasKey('foo', $array['structure']);
        $this->assertArrayHasKey('bar', $array['structure']);
    }

    public function testAllowsComplexFilters()
    {
        $that = $this;
        $method = function ($a, $b, $c) use ($that) {
            $that->assertEquals('test', $a);
            $that->assertEquals('my_value!', $b);
            $that->assertEquals('bar', $c);
            return 'abc' . $b;
        };

        $param = new ApiParam(array(
            'filters' => array(
                array(
                    'method' => $method,
                    'args'   => array('test', '@value', 'bar')
                )
            ),
        ));

        $this->assertEquals('abcmy_value!', $param->filter('my_value!'));
    }

    public function testCanChangeParentOfNestedParameter()
    {
        $param1 = new ApiParam(array('name' => 'parent'));
        $param2 = new ApiParam(array('name' => 'child'));
        $param2->setParent($param1);
        $this->assertSame($param1, $param2->getParent());
    }

    public function testCanRemoveFromNestedStructure()
    {
        $param1 = new ApiParam(array('name' => 'parent'));
        $param2 = new ApiParam(array('name' => 'child'));
        $param1->addStructure($param2);
        $this->assertSame($param1, $param2->getParent());
        $this->assertSame($param2, $param1->getStructure('child'));

        // Remove a single child from the structure
        $param1->removeStructure('child');
        $this->assertNull($param1->getStructure('child'));
        // Remove the entire structure
        $param1->addStructure($param2);
        $param1->removeStructure();
        $this->assertNull($param1->getStructure('child'));
    }
}
