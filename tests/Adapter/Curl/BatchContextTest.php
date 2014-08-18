<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

use GuzzleHttp\Adapter\Curl\BatchContext;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;

/**
 * @covers GuzzleHttp\Adapter\Curl\BatchContext
 */
class BatchContextTest extends \PHPUnit_Framework_TestCase
{
    public function testProvidesGetters()
    {
        $m = curl_multi_init();
        $b = new BatchContext($m, true);
        $this->assertTrue($b->throwsExceptions());
        $this->assertSame($m, $b->getMultiHandle());
        $this->assertFalse($b->hasPending());
        curl_multi_close($m);
    }

    public function testValidatesTransactionsAreNotAddedTwice()
    {
        $m = curl_multi_init();
        $b = new BatchContext($m, true);
        $h = curl_init();
        $t = new Transaction(
            new Client(),
            new Request('GET', 'http://httbin.org')
        );
        $b->addTransaction($t, $h);
        try {
            $b->addTransaction($t, $h);
            $this->fail('Did not throw');
        } catch (\RuntimeException $e) {
            curl_close($h);
            curl_multi_close($m);
        }
    }

    public function testManagesHandles()
    {
        $m = curl_multi_init();
        $b = new BatchContext($m, true);
        $h = curl_init();
        $t = new Transaction(
            new Client(),
            new Request('GET', 'http://httbin.org')
        );
        $b->addTransaction($t, $h);
        $this->assertTrue($b->isActive());
        $this->assertSame($t, $b->findTransaction($h));
        $b->removeTransaction($t);
        $this->assertFalse($b->isActive());
        try {
            $this->assertEquals([], $b->findTransaction($h));
            $this->fail('Did not throw');
        } catch (\RuntimeException $e) {}
        curl_multi_close($m);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Transaction not registered
     */
    public function testThrowsWhenRemovingNonExistentTransaction()
    {
        $b = new BatchContext('foo', false);
        $t = new Transaction(
            new Client(),
            new Request('GET', 'http://httbin.org')
        );
        $b->removeTransaction($t);
    }

    public function testReturnsPendingAsIteratorTypeObject()
    {
        $t1 = new Transaction(new Client(), new Request('GET', 'http://t.com'));
        $t2 = new Transaction(new Client(), new Request('GET', 'http://t.com'));
        $t3 = new Transaction(new Client(), new Request('GET', 'http://t.com'));
        $iter = new \ArrayIterator([$t1, $t2, $t3]);
        $b = new BatchContext('foo', false, $iter);
        $this->assertTrue($b->hasPending());
        $this->assertSame($t1, $b->nextPending());
        $this->assertTrue($b->hasPending());
        $this->assertSame($t2, $b->nextPending());
        $this->assertTrue($b->hasPending());
        $this->assertSame($t3, $b->nextPending());
        $this->assertFalse($b->hasPending());
        $this->assertNull($b->nextPending());
    }

    public function testCanCloseAll()
    {
        $m = curl_multi_init();
        $b = new BatchContext($m, true);
        $h = curl_init();
        $t = new Transaction(
            new Client(),
            new Request('GET', 'http://httbin.org')
        );
        $b->addTransaction($t, $h);
        $b->removeAll();
        $this->assertFalse($b->isActive());
        $this->assertEquals(0, count($this->readAttribute($b, 'handles')));
        curl_multi_close($m);
    }
}
