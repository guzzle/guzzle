<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Event\ProgressEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Transaction;

/**
 * @covers GuzzleHttp\Event\ProgressEvent
 */
class ProgressEventTest extends \PHPUnit_Framework_TestCase
{
    public function testContainsNumbers()
    {
        $t = new Transaction(new Client(), new Request('GET', 'http://a.com'));
        $p = new ProgressEvent($t, 2, 1, 3, 0);
        $this->assertSame($t->request, $p->getRequest());
        $this->assertSame($t->client, $p->getClient());
        $this->assertEquals(2, $p->downloadSize);
        $this->assertEquals(1, $p->downloaded);
        $this->assertEquals(3, $p->uploadSize);
        $this->assertEquals(0, $p->uploaded);
    }
}
