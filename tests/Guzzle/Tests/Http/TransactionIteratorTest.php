<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\Client;
use Guzzle\Http\TransactionIterator;

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
            $client->createRequest('GET', '/'),
            $client->createRequest('POST', '/'),
            $client->createRequest('PUT', '/'),
        ];
        $trans = new TransactionIterator($requests, $client, []);
        $this->assertEquals(0, $trans->key());
        $this->assertTrue($trans->valid());
        $this->assertEquals('GET', $trans->current()->getRequest()->getMethod());
        $trans->next();
        $this->assertEquals(1, $trans->key());
        $this->assertTrue($trans->valid());
        $this->assertEquals('POST', $trans->current()->getRequest()->getMethod());
        $trans->next();
        $this->assertEquals(2, $trans->key());
        $this->assertTrue($trans->valid());
        $this->assertEquals('PUT', $trans->current()->getRequest()->getMethod());
    }

    public function testCanForeach()
    {
        $client = new Client();
        $requests = [
            $client->createRequest('GET', '/'),
            $client->createRequest('POST', '/'),
            $client->createRequest('PUT', '/'),
        ];

        $trans = new TransactionIterator(new \ArrayIterator($requests), $client, []);
        $methods = [];

        foreach ($trans as $t) {
            $this->assertInstanceOf('Guzzle\Http\Adapter\TransactionInterface', $t);
            $methods[] = $t->getRequest()->getMethod();
        }

        $this->assertEquals(['GET', 'POST', 'PUT'], $methods);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesEachElement()
    {
        $client = new Client();
        $requests = ['foo'];
        $trans = new TransactionIterator(new \ArrayIterator($requests), $client, []);
        iterator_to_array($trans);
    }

    public function testRegistersEvents()
    {
        $fn = function() {};
        $client = new Client();
        $requests = [$client->createRequest('GET', '/')];
        $trans = new TransactionIterator(new \ArrayIterator($requests), $client, [
            'before' => $fn,
            'after' => $fn,
            'error' => $fn,
        ]);

        foreach ($trans as $t) {
            $this->assertSame($fn, $t->getRequest()->getEmitter()->listeners('request.before_send')[1]);
            $this->assertSame($fn, $t->getRequest()->getEmitter()->listeners('request.error')[0]);
            $this->assertSame($fn, $t->getRequest()->getEmitter()->listeners('request.after_send')[2]);
        }
    }
}
