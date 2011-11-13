<?php

namespace Guzzle\Tests\Common\Filter;

use Guzzle\Common\Filter;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class FilterImpTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\Filter\StringFilter
     */
    public function testStringFilter()
    {
        $filter = new Filter\StringFilter();
        $this->assertTrue($filter->process('test'));
        $this->assertEquals('The supplied value is not a string: integer supplied', $filter->process(123));
        $this->assertEquals('The supplied value is not a string: object supplied', $filter->process(new \stdClass()));
    }

    /**
     * @covers Guzzle\Common\Filter\IntegerFilter
     */
    public function testIntegerFilter()
    {
        $filter = new Filter\IntegerFilter();
        $this->assertTrue($filter->process('123'));
        $this->assertTrue($filter->process(123));
        $this->assertEquals('The supplied value is not a valid integer: 123.1 supplied', $filter->process(123.1));
        $this->assertEquals('The supplied value is not a valid integer: 123.1 supplied', $filter->process('123.1'));
    }

    /**
     * @covers Guzzle\Common\Filter\FloatFilter
     */
    public function testFloatFilter()
    {
        $filter = new Filter\FloatFilter();
        $this->assertTrue($filter->process('123'));
        $this->assertTrue($filter->process(123));
        $this->assertTrue($filter->process(123.1));
        $this->assertTrue($filter->process('123.1'));
        $this->assertEquals('The supplied value is not a valid float: string supplied', $filter->process('abc'));
        $this->assertEquals('The supplied value is not a valid float: object supplied', $filter->process(new \stdClass()));
    }

    /**
     * @covers Guzzle\Common\Filter\TimestampFilter
     */
    public function testTimestampFilter()
    {
        $filter = new Filter\TimestampFilter();
        $this->assertTrue($filter->process(time()));
        $this->assertEquals('The supplied value is not a valid timestamp: abc supplied', $filter->process('abc'));
        $this->assertEquals('The supplied value is not a valid timestamp: -20 supplied', $filter->process(-20));
        $this->assertEquals('The supplied value is not a valid timestamp: object supplied', $filter->process(new \stdClass()));
    }

    /**
     * @covers Guzzle\Common\Filter\DateFilter
     */
    public function testDateFilter()
    {
        $filter = new Filter\DateFilter();
        $this->assertTrue($filter->process('now'));
        $this->assertTrue($filter->process('1984-11-20 00:00:00'));
        $this->assertEquals('The supplied value is not a valid date: abc supplied', $filter->process('abc'));
        $this->assertEquals('The supplied value is not a valid date: 123 supplied', $filter->process(123));
        $this->assertEquals('The supplied value is not a valid date: object supplied', $filter->process(new \stdClass()));
    }

    /**
     * @covers Guzzle\Common\Filter\BooleanFilter
     */
    public function testBooleanFilter()
    {
        $filter = new Filter\BooleanFilter();
        $this->assertTrue($filter->process(true));
        $this->assertTrue($filter->process('true'));
        $this->assertTrue($filter->process(1));
        $this->assertTrue($filter->process(false));
        $this->assertTrue($filter->process('false'));
        $this->assertTrue($filter->process(0));

        $this->assertEquals('The supplied value is not a Boolean: abc supplied', $filter->process('abc'));
        $this->assertEquals('The supplied value is not a Boolean: 123 supplied', $filter->process(123));
        $this->assertEquals('The supplied value is not a Boolean: object supplied', $filter->process(new \stdClass()));
    }

    /**
     * @covers Guzzle\Common\Filter\ClassFilter
     */
    public function testClassFilter()
    {
        $filter = new Filter\ClassFilter(array('stdClass'));
        $this->assertTrue($filter->process(new \stdClass()));
        $this->assertEquals('The supplied value is not an instance of stdClass: <string:abc> supplied', $filter->process('abc'));
        $this->assertEquals('The supplied value is not an instance of stdClass: <integer:123> supplied', $filter->process(123));
        $this->assertEquals('The supplied value is not an instance of stdClass: ' . __CLASS__ . ' supplied', $filter->process($this));

        $filter = new Filter\ClassFilter();
        $this->assertTrue($filter->process(new \stdClass()));
        $this->assertEquals('The supplied value is not an instance of stdClass: <string:abc> supplied', $filter->process('abc'));
    }

    /**
     * @covers Guzzle\Common\Filter\ArrayFilter
     */
    public function testAraryFilter()
    {
        $filter = new Filter\ArrayFilter(array('stdClass'));
        $this->assertTrue($filter->process(array()));
        $this->assertEquals('The supplied value is not an array: string supplied', $filter->process('abc'));
        $this->assertEquals('The supplied value is not an array: object supplied', $filter->process($this));
    }

    /**
     * @covers Guzzle\Common\Filter\EnumFilter
     */
    public function testEnumFilter()
    {
        $filter = new Filter\EnumFilter(array('a,b,c'));
        $this->assertTrue($filter->process('a'));
        $this->assertTrue($filter->process('b'));
        $this->assertTrue($filter->process('c'));
        $this->assertEquals('The supplied argument was not found in the list of acceptable values (a, b, c): <string:abc> supplied', $filter->process('abc'));
        $this->assertEquals('The supplied argument was not found in the list of acceptable values (a, b, c): object supplied', $filter->process($this));
    }

    /**
     * @covers Guzzle\Common\Filter\RegexFilter
     */
    public function testRegexFilter()
    {
        $filter = new Filter\RegexFilter(array('/[0-9a-z]+/'));
        $this->assertTrue($filter->process('a'));
        $this->assertTrue($filter->process('this_is_a_slug_2011'));
        $this->assertEquals('The supplied argument did not match the regular expression /[0-9a-z]+/: A@@ supplied', $filter->process('A@@'));
        $this->assertEquals('The supplied argument must be a string to match the RegexFilter: object supplied', $filter->process($this));

        // With non-string
        $filter = new Filter\RegexFilter(array($this));
        $this->assertTrue($filter->process('a'));

        // With no value
        $filter = new Filter\RegexFilter(array());
        $this->assertTrue($filter->process('a'));
    }
}