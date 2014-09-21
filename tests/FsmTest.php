<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\StateException;
use GuzzleHttp\Transaction;
use GuzzleHttp\Fsm;

class FsmTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \RuntimeException
     */
    public function testValidatesStateNames()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httpbin.org');
        (new Fsm('foo', []))->run(new Transaction($client, $request));
    }

    public function testTransitionsThroughStates()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httpbin.org');
        $t = new Transaction($client, $request);
        $c = [];
        $fsm = new Fsm('begin', [
            'begin' => [
                'success' => 'end',
                'transition' => function (Transaction $trans) use ($t, &$c) {
                    $this->assertSame($t, $trans);
                    $c[] = 'begin';
                }
            ],
            'end' => [
                'transition' => function (Transaction $trans) use ($t, &$c) {
                    $this->assertSame($t, $trans);
                    $c[] = 'end';
                }
            ],
        ]);

        $fsm->run($t);
        $this->assertEquals(['begin', 'end'], $c);
    }

    public function testTransitionsThroughErrorStates()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httpbin.org');
        $t = new Transaction($client, $request);
        $c = [];

        $fsm = new Fsm('begin', [
            'begin' => [
                'success' => 'end',
                'error'   => 'error',
                'transition' => function (Transaction $trans) use ($t, &$c) {
                    $c[] = 'begin';
                    throw new \OutOfBoundsException();
                }
            ],
            'error' => [
                'success' => 'end',
                'error'   => 'end',
                'transition' => function (Transaction $trans) use ($t, &$c) {
                    $c[] = 'error';
                    $this->assertInstanceOf('OutOfBoundsException', $t->exception);
                    $trans->exception = null;
                }
            ],
            'end' => [
                'transition' => function (Transaction $trans) use ($t, &$c) {
                    $c[] = 'end';
                }
            ],
        ]);

        $fsm->run($t);
        $this->assertEquals(['begin', 'error', 'end'], $c);
        $this->assertNull($t->exception);
    }

    public function testThrowsTerminalErrors()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httpbin.org');
        $t = new Transaction($client, $request);

        $fsm = new Fsm('begin', [
            'begin' => [
                'transition' => function (Transaction $trans) use ($t) {
                    throw new \OutOfBoundsException();
                }
            ]
        ]);

        try {
            $fsm->run($t);
            $this->fail('Did not throw');
        } catch (\OutOfBoundsException $e) {
            $this->assertSame($e, $t->exception);
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Too many state transitions
     */
    public function testThrowsWhenTooManyTransitions()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httpbin.org');
        $t = new Transaction($client, $request);
        $fsm = new Fsm('begin', ['begin' => ['success' => 'begin']], 10);
        $fsm->run($t);
    }

    /**
     * @expectedExceptionMessage Foo
     * @expectedException \GuzzleHttp\Exception\StateException
     */
    public function testThrowsWhenStateException()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://httpbin.org');
        $t = new Transaction($client, $request);
        $fsm = new Fsm('begin', [
            'begin' => [
                'transition' => function () use ($request) {
                    throw new StateException('Foo');
                },
                'error' => 'not_there'
            ]
        ]);
        $fsm->run($t);
    }
}
