<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Plugin\ExponentialBackoff;

use Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Pool\Pool;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ExponentialBackoffPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function delayClosure($retries)
    {
        return 0;
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::__construct
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::getFailureCodes
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::getMaxRetries
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::setMaxRetries
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::setFailureCodes
     */
    public function testConstructsCorrectly()
    {
        $plugin = new ExponentialBackoffPlugin(2, array(500, 503, 502), array($this, 'delayClosure'));
        $this->assertEquals(2, $plugin->getMaxRetries());
        $this->assertEquals(array(500, 503, 502), $plugin->getFailureCodes());

        // You can specify any codes you want... Probably not a good idea though
        $plugin->setFailureCodes(array(200, 204));
        $this->assertEquals(array(200, 204), $plugin->getFailureCodes());
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::calculateWait
     */
    public function testCalculateWait()
    {
        $plugin = new ExponentialBackoffPlugin(2);
        $this->assertEquals(1, $plugin->calculateWait(0));
        $this->assertEquals(2, $plugin->calculateWait(1));
        $this->assertEquals(4, $plugin->calculateWait(2));
        $this->assertEquals(8, $plugin->calculateWait(3));
        $this->assertEquals(16, $plugin->calculateWait(4));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin
     */
    public function testRetriesRequests()
    {
        // Create a script to return several 500 and 503 response codes
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata"
        ));

        // Clear out other requests that have been received by the server
        $this->getServer()->flush();
        
        $plugin = new ExponentialBackoffPlugin(2, null, array($this, 'delayClosure'));
        $request = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $plugin->attach($request);
        $request->send();

        // Make sure it eventually completed successfully
        $this->assertEquals(200, $request->getResponse()->getStatusCode());
        $this->assertEquals('OK', $request->getResponse()->getReasonPhrase());
        $this->assertEquals('data', $request->getResponse()->getBody(true));

        // Check that three requests were made to retry this request
        $this->assertEquals(3, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::update
     * @covers Guzzle\Http\Message\Request
     * @expectedException Guzzle\Http\Message\BadResponseException
     */
    public function testAllowsFailureOnMaxRetries()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n"
        ));

        $plugin = new ExponentialBackoffPlugin(2, null, array($this, 'delayClosure'));
        $request = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $plugin->attach($request);

        // This will fail because the plugin isn't retrying the request because
        // the max number of retries is exceeded (1 > 0)
        $request->send();
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffPlugin::update
     * @covers Guzzle\Http\Pool\Pool
     * @covers Guzzle\Http\Plugin\ExponentialBackoff\ExponentialBackoffObserver
     */
    public function testRetriesPooledRequestsUsingDelayAndPollingEvent()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata"
        ));

        // Need to sleep for one second to make sure that the polling works
        // correctly in the observer
        $plugin = new ExponentialBackoffPlugin(1, null, function($r) {
            return 1;
        });
        
        $request = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $plugin->attach($request);

        $pool = new Pool();
        $pool->addRequest($request);
        $pool->send();

        // Make sure it eventually completed successfully
        $this->assertEquals('data', $request->getResponse()->getBody(true));

        // Check that two requests were made to retry this request
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests(false)));
    }
}