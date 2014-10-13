<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\BatchResults;

/**
 * @covers \GuzzleHttp\BatchResults
 */
class BatchResultsTest extends \PHPUnit_Framework_TestCase
{
    public function testExposesResults()
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $c = new \stdClass();
        $hash = new \SplObjectStorage();
        $hash[$a] = '1';
        $hash[$b] = '2';
        $hash[$c] = new \Exception('foo');

        $batch = new BatchResults($hash);
        $this->assertCount(3, $batch);
        $this->assertEquals([$a, $b, $c], $batch->getKeys());
        $this->assertEquals([$hash[$c]], $batch->getFailures());
        $this->assertEquals(['1', '2'], $batch->getSuccessful());
        $this->assertEquals('1', $batch->getResult($a));
        $this->assertNull($batch->getResult(new \stdClass()));
        $this->assertTrue(isset($batch[0]));
        $this->assertFalse(isset($batch[10]));
        $this->assertEquals('1', $batch[0]);
        $this->assertEquals('2', $batch[1]);
        $this->assertNull($batch[100]);
        $this->assertInstanceOf('Exception', $batch[2]);

        $results = iterator_to_array($batch);
        $this->assertEquals(['1', '2', $hash[$c]], $results);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testCannotSetByIndex()
    {
        $hash = new \SplObjectStorage();
        $batch = new BatchResults($hash);
        $batch[10] = 'foo';
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testCannotUnsetByIndex()
    {
        $hash = new \SplObjectStorage();
        $batch = new BatchResults($hash);
        unset($batch[10]);
    }
}
