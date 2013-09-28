<?php

namespace Guzzle\Tests\Url;

use Guzzle\Url\PhpAggregator;

/**
 * @covers \Guzzle\Url\PhpAggregator
 * @covers \Guzzle\Url\AbstractAggregator
 */
class PhpAggregatorTest extends \PHPUnit_Framework_TestCase
{
    private $encodeData = [
        't' => [
            'v1' => ['a', '1'],
            'v2' => 'b',
            'v3' => ['v4' => 'c', 'v5' => 'd']
        ]
    ];

    public function testEncodes()
    {
        $agg = new PhpAggregator();
        $result = $agg->aggregate($this->encodeData);
        $this->assertEquals(array(
            't[v1][0]' => ['a'],
            't[v1][1]' => ['1'],
            't[v2]' => ['b'],
            't[v3][v4]' => ['c'],
            't[v3][v5]' => ['d'],
        ), $result);
    }

    public function testEncodesNoNumericIndices()
    {
        $agg = new PhpAggregator(false);
        $result = $agg->aggregate($this->encodeData);
        $this->assertEquals(array(
            't[v1][]' => ['a', '1'],
            't[v2]' => ['b'],
            't[v3][v4]' => ['c'],
            't[v3][v5]' => ['d'],
        ), $result);
    }
}
