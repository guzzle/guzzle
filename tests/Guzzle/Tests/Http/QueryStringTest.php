<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\QueryString;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class QueryStringTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * The query string object to test
     *
     * @var \Guzzle\Http\QueryString
     */
    protected $q;

    public function setup()
    {
        $this->q = new QueryString();
    }

    /**
     * @covers \Guzzle\Http\QueryString::getFieldSeparator
     */
    public function testGetFieldSeparator()
    {
        $this->assertEquals('&', $this->q->getFieldSeparator());
    }

    /**
     * @covers \Guzzle\Http\QueryString::getPrefix
     */
    public function testGetPrefix()
    {
        $this->assertEquals('?', $this->q->getPrefix());
    }

    /**
     * @covers \Guzzle\Http\QueryString::getValueSeparator
     */
    public function testGetValueSeparator()
    {
        $this->assertEquals('=', $this->q->getValueSeparator());
    }

    /**
     * @covers \Guzzle\Http\QueryString::isEncodingFields
     * @covers \Guzzle\Http\QueryString::setEncodeFields
     */
    public function testIsEncodingFields()
    {
        $this->assertTrue($this->q->isEncodingFields());
        $this->assertEquals($this->q, $this->q->setEncodeFields(false));
        $this->assertFalse($this->q->isEncodingFields());
    }

    /**
     * @covers \Guzzle\Http\QueryString::isEncodingValues
     * @covers \Guzzle\Http\QueryString::setEncodeValues
     */
    public function testIsEncodingValues()
    {
        $this->assertTrue($this->q->isEncodingValues());
        $this->assertEquals($this->q, $this->q->setEncodeValues(false));
        $this->assertFalse($this->q->isEncodingValues());
    }

    /**
     * @covers \Guzzle\Http\QueryString::setFieldSeparator
     * @covers \Guzzle\Http\QueryString::setFieldSeparator
     */
    public function testSetFieldSeparator()
    {
        $this->assertEquals($this->q, $this->q->setFieldSeparator('/'));
        $this->assertEquals('/', $this->q->getFieldSeparator());
    }

    /**
     * @covers \Guzzle\Http\QueryString::setPrefix
     * @covers \Guzzle\Http\QueryString::getPrefix
     */
    public function testSetPrefix()
    {
        $this->assertEquals($this->q, $this->q->setPrefix(''));
        $this->assertEquals('', $this->q->getPrefix());
    }

    /**
     * @covers \Guzzle\Http\QueryString::setValueSeparator
     * @covers \Guzzle\Http\QueryString::getValueSeparator
     */
    public function testSetValueSeparator()
    {
        $this->assertEquals($this->q, $this->q->setValueSeparator('/'));
        $this->assertEquals('/', $this->q->getValueSeparator());
    }

    /**
     * @covers \Guzzle\Http\QueryString::urlEncode
     * @covers \Guzzle\Http\QueryString::rawUrlEncode
     * @covers \Guzzle\Http\QueryString::encodeData
     * @covers \Guzzle\Http\QueryString::replace
     */
    public function testUrlEncode()
    {
        $params = array(
            'test' => 'value',
            'test 2' => 'this is a test?',
            'test3' => array('v1', 'v2', 'v3')
        );
        $encoded = array(
            'test' => 'value',
            rawurlencode('test 2') => rawurlencode('this is a test?'),
            'test3[0]' => 'v1',
            'test3[1]' => 'v2',
            'test3[2]' => 'v3'
        );
        $this->q->replace($params);
        $this->assertEquals($encoded, $this->q->urlEncode());

        // Disable field encoding
        $testData = array(
            'test 2' => 'this is a test'
        );
        $this->q->replace($testData);
        $this->q->setEncodeFields(false);
        $this->assertEquals(array(
            'test 2' => rawurlencode('this is a test')
        ), $this->q->urlEncode());

        // Disable encoding of both fields and values
        $this->q->setEncodeValues(false);
        $this->assertEquals($testData, $this->q->urlEncode());


        $this->assertEquals('one&two%3D', QueryString::rawurlencode('one&two=', array('&')));
    }

    /**
     * @covers \Guzzle\Http\QueryString
     * @covers \Guzzle\Http\QueryString::__toString
     * @covers \Guzzle\Http\QueryString::setEncodeFields
     * @covers \Guzzle\Http\QueryString::replace
     * @covers \Guzzle\Http\QueryString::setAggregateFunction
     * @covers \Guzzle\Http\QueryString::encodeData
     */
    public function testToString()
    {
        // Check with no parameters
        $this->assertEquals('', $this->q->__toString());

        $params = array(
            'test' => 'value',
            'test 2' => 'this is a test?',
            'test3' => array(
                'v1',
                'v2',
                'v3'
            )
        );
        $this->q->replace($params);
        $this->assertEquals('?test=value&test%202=this%20is%20a%20test%3F&test3[0]=v1&test3[1]=v2&test3[2]=v3', $this->q->__toString());
        $this->q->setEncodeFields(false);
        $this->q->setEncodeValues(false);
        $this->assertEquals('?test=value&test 2=this is a test?&test3[0]=v1&test3[1]=v2&test3[2]=v3', $this->q->__toString());

        // Use an alternative aggregator
        $this->q->setAggregateFunction(array($this->q, 'aggregateUsingComma'));
        $this->assertEquals('?test=value&test 2=this is a test?&test3=v1,v2,v3', $this->q->__toString());
    }
}