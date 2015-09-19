<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Query;

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
        $q = new Query(['foo' => ['bar', 'baz']]);
        $q->setAggregator(function (array $data) {
            return ['foo' => ['barANDbaz']];
        });
        $this->assertEquals('foo=barANDbaz', (string) $q);
    }

    public function testAllowsMultipleValuesPerKey()
    {
        $q = new Query();
        $q->add('facet', 'size');
        $q->add('facet', 'width');
        $q->add('facet.field', 'foo');
        // Use the duplicate aggregator
        $q->setAggregator($q::duplicateAggregator());
        $this->assertEquals('facet=size&facet=width&facet.field=foo', (string) $q);
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

    private $encodeData = [
        't' => [
            'v1' => ['a', '1'],
            'v2' => 'b',
            'v3' => ['v4' => 'c', 'v5' => 'd']
        ]
    ];

    public function testEncodesDuplicateAggregator()
    {
        $agg = Query::duplicateAggregator();
        $result = $agg($this->encodeData);
        $this->assertEquals(array(
            't[v1]' => ['a', '1'],
            't[v2]' => ['b'],
            't[v3][v4]' => ['c'],
            't[v3][v5]' => ['d'],
        ), $result);
    }

    public function testDuplicateEncodesNoNumericIndices()
    {
        $agg = Query::duplicateAggregator();
        $result = $agg($this->encodeData);
        $this->assertEquals(array(
            't[v1]' => ['a', '1'],
            't[v2]' => ['b'],
            't[v3][v4]' => ['c'],
            't[v3][v5]' => ['d'],
        ), $result);
    }

    public function testEncodesPhpAggregator()
    {
        $agg = Query::phpAggregator();
        $result = $agg($this->encodeData);
        $this->assertEquals(array(
            't[v1][0]' => ['a'],
            't[v1][1]' => ['1'],
            't[v2]' => ['b'],
            't[v3][v4]' => ['c'],
            't[v3][v5]' => ['d'],
        ), $result);
    }

    public function testPhpEncodesNoNumericIndices()
    {
        $agg = Query::phpAggregator(false);
        $result = $agg($this->encodeData);
        $this->assertEquals(array(
            't[v1][]' => ['a', '1'],
            't[v2]' => ['b'],
            't[v3][v4]' => ['c'],
            't[v3][v5]' => ['d'],
        ), $result);
    }

    public function testCanDisableUrlEncodingDecoding()
    {
        $q = Query::fromString('foo=bar+baz boo%20', false);
        $this->assertEquals('bar+baz boo%20', $q['foo']);
        $this->assertEquals('foo=bar+baz boo%20', (string) $q);
    }

    public function testCanChangeUrlEncodingDecodingToRfc1738()
    {
        $q = Query::fromString('foo=bar+baz', Query::RFC1738);
        $this->assertEquals('bar baz', $q['foo']);
        $this->assertEquals('foo=bar+baz', (string) $q);
    }

    public function testCanChangeUrlEncodingDecodingToRfc3986()
    {
        $q = Query::fromString('foo=bar%20baz', Query::RFC3986);
        $this->assertEquals('bar baz', $q['foo']);
        $this->assertEquals('foo=bar%20baz', (string) $q);
    }

    public function testQueryStringsAllowSlashButDoesNotDecodeWhenDisable()
    {
        $q = Query::fromString('foo=bar%2Fbaz&bam=boo%20boo', Query::RFC3986);
        $q->setEncodingType(false);
        $this->assertEquals('foo=bar/baz&bam=boo boo', (string) $q);
    }

    public function testQueryStringsAllowDecodingEncodingCompletelyDisabled()
    {
        $q = Query::fromString('foo=bar%2Fbaz&bam=boo boo!', false);
        $this->assertEquals('foo=bar%2Fbaz&bam=boo boo!', (string) $q);
    }
}
