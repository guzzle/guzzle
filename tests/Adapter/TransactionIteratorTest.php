<?php

namespace GuzzleHttp\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Adapter\TransactionIterator;

class TransactionIteratorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesConstructor()
    {
        new TransactionIterator('foo', new Client(), []);
    }

    public function testCreatesTransactions()
    {
        $client = new Client();
        $requests = [
            $client->createRequest('GET', 'http://test.com'),
            $client->createRequest('POST', 'http://test.com'),
            $client->createRequest('PUT', 'http://test.com'),
        ];
        $t = new TransactionIterator($requests, $client, []);
        $this->assertEquals(0, $t->key());
        $this->assertTrue($t->valid());
        $this->assertEquals('GET', $t->current()->getRequest()->getMethod());
        $t->next();
        $this->assertEquals(1, $t->key());
        $this->assertTrue($t->valid());
        $this->assertEquals('POST', $t->current()->getRequest()->getMethod());
        $t->next();
        $this->assertEquals(2, $t->key());
        $this->assertTrue($t->valid());
        $this->assertEquals('PUT', $t->current()->getRequest()->getMethod());
    }

    public function testCanForeach()
    {
        $c = new Client();
        $requests = [
            $c->createRequest('GET', 'http://test.com'),
            $c->createRequest('POST', 'http://test.com'),
            $c->createRequest('PUT', 'http://test.com'),
        ];

        $t = new TransactionIterator(new \ArrayIterator($requests), $c, []);
        $methods = [];

        foreach ($t as $trans) {
            $this->assertInstanceOf(
                'GuzzleHttp\Adapter\TransactionInterface',
                $trans
            );
            $methods[] = $trans->getRequest()->getMethod();
        }

        $this->assertEquals(['GET', 'POST', 'PUT'], $methods);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesEachElement()
    {
        $c = new Client();
        $requests = ['foo'];
        $t = new TransactionIterator(new \ArrayIterator($requests), $c, []);
        iterator_to_array($t);
    }

    public function testRegistersEvents()
    {
        $fn = function() {};
        $c = new Client();
        $requests = [$c->createRequest('GET', 'http://test.com')];
        $trans = new TransactionIterator(new \ArrayIterator($requests), $c, [
            'before'   => $fn,
            'complete' => $fn,
            'error'    => $fn,
        ]);

        $t = iterator_to_array($trans)[0];
        $em = $t->getRequest()->getEmitter();
        $this->assertSame($fn, $em->listeners('before')[0]);
        $this->assertSame($fn, $em->listeners('complete')[2]);
        $this->assertSame($fn, $em->listeners('error')[0]);
    }

    public function testRegistersEventsWithPriorities()
    {
        $fn = function() {};
        $client = new Client();
        $requests = [$client->createRequest('GET', 'http://test.com')];
        $trans = new TransactionIterator(
            new \ArrayIterator($requests),
            $client,
            [
                'before'   => [['fn' => $fn, 'priority' => 99]],
                'complete' => [['fn' => $fn, 'priority' => 99]],
                'error'    => [['fn' => $fn, 'priority' => 99]]
            ]
        );

        $t = iterator_to_array($trans)[0];
        $em = $t->getRequest()->getEmitter();
        $this->assertSame($fn, $em->listeners('before')[0]);
        $this->assertSame($fn, $em->listeners('complete')[2]);
        $this->assertSame($fn, $em->listeners('error')[0]);
    }

    public function testRegistersMultipleEvents()
    {
        $fn = function() {};
        $c = new Client();
        $eventArray = [['fn' => $fn], ['fn' => $fn]];
        $requests = [$c->createRequest('GET', 'http://test.com')];
        $trans = new TransactionIterator(new \ArrayIterator($requests), $c, [
            'before'   => $eventArray,
            'complete' => $eventArray,
            'error'    => $eventArray,
        ]);

        $t = iterator_to_array($trans)[0];
        $em = $t->getRequest()->getEmitter();
        $this->assertSame($fn, $em->listeners('before')[0]);
        $this->assertSame($fn, $em->listeners('before')[1]);
        $this->assertSame($fn, $em->listeners('complete')[2]);
        $this->assertSame($fn, $em->listeners('complete')[3]);
        $this->assertSame($fn, $em->listeners('error')[0]);
        $this->assertSame($fn, $em->listeners('error')[1]);
    }

    public function testRegistersEventsWithOnce()
    {
        $called = 0;
        $fn = function () use (&$called) { $called++; };
        $client = new Client();
        $requests = [$client->createRequest('GET', 'http://test.com')];
        // Remove an default listeners
        foreach ($requests[0]->getEmitter()->listeners('before') as $l) {
            $requests[0]->getEmitter()->removeListener('before', $l);
        }
        $trans = new TransactionIterator(
            new \ArrayIterator($requests),
            $client,
            ['before' => [['fn' => $fn, 'once' => true]]]
        );
        // Apply the listeners to the request
        iterator_to_array($trans)[0];
        $ev = $this->getMockBuilder('GuzzleHttp\Event\BeforeEvent')
            ->disableOriginalConstructor()
            ->getMock();
        $requests[0]->getEmitter()->emit('before', $ev);
        $requests[0]->getEmitter()->emit('before', $ev);
        $this->assertEquals(1, $called);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEvents()
    {
        $client = new Client();
        $requests = [$client->createRequest('GET', 'http://test.com')];
        new TransactionIterator(new \ArrayIterator($requests), $client, [
            'before' => 'foo'
        ]);
    }
}
