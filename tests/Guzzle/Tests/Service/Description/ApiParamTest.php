<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ApiParam;

/**
 * @covers Guzzle\Service\Description\ApiParam
 */
class ApiParamTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $data = array(
        'name'       => 'foo',
        'type'       => 'bar',
        'required'   => true,
        'default'    => '123',
        'doc'        => '456',
        'min_length' => 2,
        'max_length' => 5,
        'location'   => 'body',
        'static'     => 'static!',
        'prepend'    => 'before.',
        'append'     => '.after',
        'filters'    => 'trim,json_encode'
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

    public function testFromArrayConvertsBooleans()
    {
        $d = $this->data;

        $d['required'] = 'false';
        $p = new ApiParam($d);
        $this->assertEquals(false, $p->getRequired());

        $d['required'] = 'true';
        $p = new ApiParam($d);
        $this->assertEquals(true, $p->getRequired());
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

    public function testConvertsBooleanValues()
    {
        $d = $this->data;

        $d['static'] = 'true';
        $p = new ApiParam($d);
        $this->assertEquals(true, $p->getValue(null));

        $d['static'] = 'false';
        $p = new ApiParam($d);
        $this->assertEquals(false, $p->getValue(null));
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
}
