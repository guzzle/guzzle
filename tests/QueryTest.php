<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Query;
use GuzzleHttp\QueryAggregator\DuplicateAggregator;

class QueryTest extends \PHPUnit_Framework_TestCase
{
    public function testCanCastToString()
    {
        $q = new Query(['foo' => 'baz', 'bar' => 'bam boozle']);
        $this->assertEquals('foo=baz&bar=bam%20boozle', (string) $q);
    }

    public function testCanDisableUrlEncoding()
    {
        $q = new Query(['bar' => 'bam boozle']);
        $q->setEncodingType(false);
        $this->assertEquals('bar=bam boozle', (string) $q);
    }

    public function testCanSpecifyRfc1783UrlEncodingType()
    {
        $q = new Query(['bar abc' => 'bam boozle']);
        $q->setEncodingType(Query::RFC1738);
        $this->assertEquals('bar+abc=bam+boozle', (string) $q);
    }

    public function testCanSpecifyRfc3986UrlEncodingType()
    {
        $q = new Query(['bar abc' => 'bam boozle', 'ሴ' => 'hi']);
        $q->setEncodingType(Query::RFC3986);
        $this->assertEquals('bar%20abc=bam%20boozle&%E1%88%B4=hi', (string) $q);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEncodingType()
    {
        (new Query(['bar' => 'bam boozle']))->setEncodingType('foo');
    }

    public function testAggregatesMultipleValues()
    {
        $q = new Query(['foo' => ['bar', 'baz']]);
        $this->assertEquals('foo%5B0%5D=bar&foo%5B1%5D=baz', (string) $q);
    }

    public function testCanSetAggregator()
    {
        $agg = $this->getMockBuilder('GuzzleHttp\QueryAggregator\QueryAggregatorInterface')
            ->setMethods('aggregate')
            ->getMockForAbstractClass();

        $q = new Query(['foo' => ['bar', 'baz']]);
        $q->setAggregator($agg);

        $agg->expects($this->once())
            ->method('aggregate')
            ->will($this->returnValue(['foo' => ['barANDbaz']]));

        $this->assertEquals('foo=barANDbaz', (string) $q);
    }

    public function testAllowsMultipleValuesPerKey()
    {
        $q = new Query();
        $q->add('facet', 'size');
        $q->add('facet', 'width');
        $q->add('facet.field', 'foo');
        // Use the duplicate aggregator
        $q->setAggregator(new DuplicateAggregator());
        $this->assertEquals('facet=size&facet=width&facet.field=foo', (string) $q);
    }

    public function parseQueryProvider()
    {
        return array(
            // Ensure that multiple query string values are allowed per value
            array('q=a&q=b', array('q' => array('a', 'b'))),
            // Ensure that PHP array style query string values are parsed
            array('q[]=a&q[]=b', array('q' => array('a', 'b'))),
            // Ensure that a single PHP array style query string value is parsed into an array
            array('q[]=a', array('q' => array('a'))),
            // Ensure that decimals are allowed in query strings
            array('q.a=a&q.b=b', array(
                'q.a' => 'a',
                'q.b' => 'b'
            )),
            // Ensure that query string values are percent decoded
            array('q%20a=a%20b', array('q a' => 'a b')),
            // Ensure null values can be added
            array('q&a', array('q' => null, 'a' => null)),
        );
    }

    /**
     * @dataProvider parseQueryProvider
     */
    public function testParsesQuerys($query, $data)
    {
        $query = Query::fromString($query);
        $this->assertEquals($data, $query->toArray());
    }

    public function testProperlyDealsWithDuplicateQueryValues()
    {
        $query = Query::fromString('foo=a&foo=b&?µ=c');
        $this->assertEquals(array('a', 'b'), $query->get('foo'));
        $this->assertEquals('c', $query->get('?µ'));
    }

    public function testAllowsNullQueryValues()
    {
        $query = Query::fromString('foo');
        $this->assertEquals('foo', (string) $query);
        $query->set('foo', null);
        $this->assertEquals('foo', (string) $query);
    }

    public function testAllowsFalsyQueryValues()
    {
        $query = Query::fromString('0');
        $this->assertEquals('0', (string) $query);
        $query->set('0', '');
        $this->assertSame('0=', (string) $query);
    }

    public function testConvertsPlusSymbolsToSpaces()
    {
        $query = Query::fromString('var=foo+bar');
        $this->assertEquals('foo bar', $query->get('var'));
    }

    public function testFromStringDoesntMangleZeroes()
    {
        $query = Query::fromString('var=0');
        $this->assertSame('0', $query->get('var'));
    }

    public function testAllowsZeroValues()
    {
        $query = new Query(array(
            'foo' => 0,
            'baz' => '0',
            'bar' => null,
            'boo' => false
        ));
        $this->assertEquals('foo=0&baz=0&bar&boo=', (string) $query);
    }

    public function testFromStringDoesntStripTrailingEquals()
    {
        $query = Query::fromString('data=mF0b3IiLCJUZWFtIERldiJdfX0=');
        $this->assertEquals('mF0b3IiLCJUZWFtIERldiJdfX0=', $query->get('data'));
    }

    public function testGuessesIfDuplicateAggregatorShouldBeUsed()
    {
        $query = Query::fromString('test=a&test=b');
        $this->assertEquals('test=a&test=b', (string) $query);
    }

    public function testGuessesIfDuplicateAggregatorShouldBeUsedAndChecksForPhpStyle()
    {
        $query = Query::fromString('test[]=a&test[]=b');
        $this->assertEquals('test%5B0%5D=a&test%5B1%5D=b', (string) $query);
    }

    public function testCastingToAndCreatingFromStringWithEmptyValuesIsFast()
    {
        $this->assertEquals('', (string) Query::fromString(''));
    }
}
