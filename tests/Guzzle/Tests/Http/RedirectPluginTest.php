<?php

namespace Guzzle\Tests\Plugin\Redirect;

use Guzzle\Http\Client;
use Guzzle\Http\RedirectPlugin;
use Guzzle\Http\Exception\TooManyRedirectsException;

/**
 * @covers Guzzle\Http\RedirectPlugin
 */
class RedirectPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testRedirectsRequests()
    {
        // Flush the server and queue up a redirect followed by a successful response
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ));

        // Create a client that uses the default redirect behavior
        $client = new Client($this->getServer()->getUrl());
        $request = $client->get('/foo');
        $response = $request->send();

        $this->assertEquals(200, $response->getStatusCode());
        $requests = $this->getServer()->getReceivedRequests(true);

        // Ensure that two requests were sent
        $this->assertEquals('/foo', $requests[0]->getResource());
        $this->assertEquals('GET', $requests[0]->getMethod());
        $this->assertEquals('/redirect', $requests[1]->getResource());
        $this->assertEquals('GET', $requests[1]->getMethod());
        $this->assertEquals('/redirect', $requests[2]->getResource());
        $this->assertEquals('GET', $requests[2]->getMethod());

        // Ensure that the previous response was set correctly
        $this->assertEquals(301, $response->getPreviousResponse()->getStatusCode());
        $this->assertEquals('/redirect', (string) $response->getPreviousResponse()->getHeader('Location'));

        // Ensure that the redirect count was incremented
        $this->assertEquals(2, $request->getParams()->get(RedirectPlugin::REDIRECT_COUNT));
    }

    public function testCanLimitNumberOfRedirects()
    {
        // Flush the server and queue up a redirect followed by a successful response
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect1\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect2\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect3\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect4\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect5\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect6\r\nContent-Length: 0\r\n\r\n"
        ));

        try {
            $client = new Client($this->getServer()->getUrl());
            $client->get('/foo')->send();
            $this->fail('Did not throw expected exception');
        } catch (TooManyRedirectsException $e) {
            // Ensure that the exception message is correct
            $message = $e->getMessage();
            $parts = explode("\n* Sending redirect request\n", $message);
            $this->assertContains('> GET /foo', $parts[0]);
            $this->assertContains('> GET /redirect1', $parts[1]);
            $this->assertContains('> GET /redirect2', $parts[2]);
            $this->assertContains('> GET /redirect3', $parts[3]);
            $this->assertContains('> GET /redirect4', $parts[4]);
            $this->assertContains('> GET /redirect5', $parts[5]);
        }
    }

    public function testDefaultBehaviorIsToRedirectWithGetForEntityEnclosingRequests()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ));

        $client = new Client($this->getServer()->getUrl());
        $client->post('/foo', array('X-Baz' => 'bar'), 'testing')->send();

        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('POST', $requests[0]->getMethod());
        $this->assertEquals('GET', $requests[1]->getMethod());
        $this->assertEquals('bar', (string) $requests[1]->getHeader('X-Baz'));
        $this->assertEquals('GET', $requests[2]->getMethod());
    }

    public function testCanRedirectWithStrictRfcCompliance()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /redirect\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ));

        $client = new Client($this->getServer()->getUrl());
        $request = $client->post('/foo', array('X-Baz' => 'bar'), 'testing');
        $request->getParams()->set(RedirectPlugin::STRICT_REDIRECTS, true);
        $request->send();

        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals('POST', $requests[0]->getMethod());
        $this->assertEquals('POST', $requests[1]->getMethod());
        $this->assertEquals('bar', (string) $requests[1]->getHeader('X-Baz'));
        $this->assertEquals('POST', $requests[2]->getMethod());
    }
}
