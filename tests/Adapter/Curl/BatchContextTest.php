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
    public function testValidatesTransactionsAreNotAddedTwice()
    {
        $m = curl_multi_init();
        $b = new BatchContext($m, true);
        $h = curl_init();
        $t = new Transaction(new Client(), new Request('GET', 'http://httbin.org'));
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
        $t = new Transaction(new Client(), new Request('GET', 'http://httbin.org'));
        $b->addTransaction($t, $h);
        $this->assertSame($t, $b->findTransaction($h));
        $b->removeTransaction($t);
        try {
            $this->assertEquals([], $b->findTransaction($h));
            $this->fail('Did not throw');
        } catch (\RuntimeException $e) {}
        curl_multi_close($m);
    }
}
