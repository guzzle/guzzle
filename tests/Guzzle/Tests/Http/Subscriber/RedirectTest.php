<?php

namespace Guzzle\Tests\Plugin\Redirect;

use Guzzle\Http\Client;
use Guzzle\Plugin\History\HistoryPlugin;
use Guzzle\Plugin\Mock\MockPlugin;

/**
 * @covers Guzzle\Http\Subscriber\Redirect
 */
class RedirectTest extends \PHPUnit_Framework_TestCase
{
    public function testRedirectsRequests()
    {
        $mock = new MockPlugin();
        $history = new HistoryPlugin();
        $mock->addMultiple([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect1\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect2\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]);

        $client = new Client(['base_url' => 'http://test.com']);
        $client->getEventDispatcher()->addSubscriber($history);
        $client->getEventDispatcher()->addSubscriber($mock);

        $response = $client->get('/foo');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('/redirect2', $response->getEffectiveUrl());

        // Ensure that two requests were sent
        $requests = $history->getRequests();

        $this->assertEquals('/foo', $requests[0]->getPath());
        $this->assertEquals('GET', $requests[0]->getMethod());
        $this->assertEquals('/redirect1', $requests[1]->getPath());
        $this->assertEquals('GET', $requests[1]->getMethod());
        $this->assertEquals('/redirect2', $requests[2]->getPath());
        $this->assertEquals('GET', $requests[2]->getMethod());
    }

    /**
     * @expectedException \Guzzle\Http\Exception\TooManyRedirectsException
     * @expectedExceptionMessage Will not follow more than
     */
    public function testCanLimitNumberOfRedirects()
    {
        $mock = new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect1\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect2\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect3\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect4\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect5\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect6\r\nContent-Length: 0\r\n\r\n"
        ]);
        $client = new Client();
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->get('http://www.example.com/foo');
    }

    public function testDefaultBehaviorIsToRedirectWithGetForEntityEnclosingRequests()
    {
        $h = new HistoryPlugin();
        $mock = new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]);
        $client = new Client();
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->getEventDispatcher()->addSubscriber($h);
        $client->post('http://test.com/foo', ['X-Baz' => 'bar'], 'testing');

        $requests = $h->getRequests();
        $this->assertEquals('POST', $requests[0]->getMethod());
        $this->assertEquals('GET', $requests[1]->getMethod());
        $this->assertEquals('bar', (string) $requests[1]->getHeader('X-Baz'));
        $this->assertEquals('GET', $requests[2]->getMethod());
    }

    public function testCanRedirectWithStrictRfcCompliance()
    {
        $h = new HistoryPlugin();
        $mock = new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]);
        $client = new Client();
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->getEventDispatcher()->addSubscriber($h);
        $client->post('/foo', ['X-Baz' => 'bar'], 'testing', ['allow_redirects' => 'strict']);

        $requests = $h->getRequests();
        $this->assertEquals('POST', $requests[0]->getMethod());
        $this->assertEquals('POST', $requests[1]->getMethod());
        $this->assertEquals('bar', (string) $requests[1]->getHeader('X-Baz'));
        $this->assertEquals('POST', $requests[2]->getMethod());
    }

    public function testRewindsStreamWhenRedirectingIfNeeded()
    {
        $h = new HistoryPlugin();
        $mock = new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]);
        $client = new Client();
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->getEventDispatcher()->addSubscriber($h);

        $body = $this->getMockBuilder('Guzzle\Stream\StreamInterface')
            ->setMethods(['seek', 'read', 'eof', 'tell'])
            ->getMockForAbstractClass();
        $body->expects($this->once())->method('tell')->will($this->returnValue(1));
        $body->expects($this->once())->method('seek')->will($this->returnValue(true));
        $body->expects($this->any())->method('eof')->will($this->returnValue(true));
        $body->expects($this->any())->method('read')->will($this->returnValue('foo'));
        $client->post('/foo', [], $body, ['allow_redirects' => 'strict']);
    }

    /**
     * @expectedException \Guzzle\Http\Exception\CouldNotRewindStreamException
     * @expectedExceptionMessage Unable to rewind the non-seekable entity body of the request after redirecting
     */
    public function testThrowsExceptionWhenStreamCannotBeRewound()
    {
        $h = new HistoryPlugin();
        $mock = new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]);
        $client = new Client();
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->getEventDispatcher()->addSubscriber($h);

        $body = $this->getMockBuilder('Guzzle\Stream\StreamInterface')
            ->setMethods(['seek', 'read', 'eof', 'tell'])
            ->getMockForAbstractClass();
        $body->expects($this->once())->method('tell')->will($this->returnValue(1));
        $body->expects($this->once())->method('seek')->will($this->returnValue(false));
        $body->expects($this->any())->method('eof')->will($this->returnValue(true));
        $body->expects($this->any())->method('read')->will($this->returnValue('foo'));
        $client->post('/foo', [], $body, ['allow_redirects' => 'strict']);
    }

    public function testRedirectsCanBeDisabledPerRequest()
    {
        $client = new Client();
        $client->getEventDispatcher()->addSubscriber(new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]));
        $response = $client->put('/', [], 'test', ['allow_redirects' => false]);
        $this->assertEquals(301, $response->getStatusCode());
    }

    public function testCanRedirectWithNoLeadingSlashAndQuery()
    {
        $h = new HistoryPlugin();
        $client = new Client(['base_url' => 'http://www.foo.com']);
        $client->getEventDispatcher()->addSubscriber(new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: redirect?foo=bar\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ]));
        $client->getEventDispatcher()->addSubscriber($h);
        $client->get('?foo=bar');
        $requests = $h->getRequests();
        $this->assertEquals('http://www.foo.com?foo=bar', $requests[0]->getUrl());
        $this->assertEquals('http://www.foo.com/redirect?foo=bar', $requests[1]->getUrl());
    }

    public function testHandlesRedirectsWithSpacesProperly()
    {
        $client = new Client(['base_url' => 'http://www.foo.com']);
        $client->getEventDispatcher()->addSubscriber(new MockPlugin([
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect 1\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ]));
        $h = new HistoryPlugin();
        $client->getEventDispatcher()->addSubscriber($h);
        $client->get('/foo');
        $reqs = $h->getRequests();
        $this->assertEquals('/redirect%201', $reqs[1]->getResource());
    }
}
