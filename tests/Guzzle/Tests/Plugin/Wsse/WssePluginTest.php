<?php

namespace Guzzle\Tests\Plugin\Wsse;

use Guzzle\Http\Message\RequestFactory;
use Guzzle\Plugin\Wsse\WssePlugin;
use Guzzle\Common\Event;

/**
 * @covers Guzzle\Plugin\Wsse\WssePlugin
 */
class WssePluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $config = array(
        'username' => 'john',
        'password' => 'doe',
    );

    protected function getRequest()
    {
        return RequestFactory::getInstance()->create('GET', 'http://example.com');
    }

    protected function getEvent($timestamp = null)
    {
        return new Event(array(
            'request' => $this->getRequest(),
            'timestamp' => $timestamp ?: time()
        ));
    }

    protected function newPlugin()
    {
        return new WssePlugin($this->config);
    }

    public function testSubscribesToEvents()
    {
        $events = WssePlugin::getSubscribedEvents();
        $this->assertArrayHasKey('request.before_send', $events);
    }

    public function testConfiguration()
    {
        $config = $this->config;
        $config['nonce_callback'] = 'test1';
        $config['timestamp_callback'] = 'test2';
        $p = new WssePlugin($config);

        // Access the config object
        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertEquals('john', $config['username']);
        $this->assertEquals('doe', $config['password']);
        $this->assertEquals('test1', $config['nonce_callback']);
        $this->assertEquals('test2', $config['timestamp_callback']);

        $p = new WssePlugin($this->config);

        // Test the default closures
        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertEquals('john', $config['username']);
        $this->assertEquals('doe', $config['password']);
        $this->assertInstanceOf('Closure', $config['nonce_callback']);
        $this->assertInstanceOf('Closure', $config['timestamp_callback']);

        $nonce = call_user_func_array($config['nonce_callback'], array($this->getEvent()));
        $this->assertInternalType('string', $nonce);
        $this->assertGreaterThanOrEqual(20, strlen($nonce));

        $time = time() - 1000;
        $timestamp = call_user_func_array($config['timestamp_callback'],  array($this->getEvent($time)));
        $this->assertInstanceOf('DateTime', $timestamp);
        $this->assertEquals($time, $timestamp->getTimestamp());
    }

    public function testDigest()
    {
        $p = $this->newPlugin();

        $this->assertEquals(
            base64_encode(sha1('nonce'.'timestamp'.'password', true)),
            $p->digest('password', 'nonce', 'timestamp')
        );
    }

    public function testCreateWsseHeader()
    {
        $p = $this->newPlugin();

        $header = $p->createWsseHeader('John', 'test', 'nonce', 'timestamp');

        $this->assertEquals(1, preg_match(
                '/UsernameToken Username="([^"]+)", PasswordDigest="([^"]+)", Nonce="([^"]+)", Created="([^"]+)"/',
                $header,
                $matches
            )
        );
        $this->assertEquals('John', $matches[1]);
        $this->assertEquals($p->digest('test', 'nonce', 'timestamp'), $matches[2]);
        $this->assertEquals('nonce', $matches[3]);
        $this->assertEquals('timestamp', $matches[4]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testOnRequestBeforeSend_BadNonceCallback()
    {
        $config = $this->config;
        $config['nonce_callback'] = 'string';

        $p = new WssePlugin($config);
        $p->onRequestBeforeSend($this->getEvent());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testOnRequestBeforeSend_BadNonceCallbackResponse()
    {
        $config = $this->config;
        $config['nonce_callback'] = function() { return 42; };

        $p = new WssePlugin($config);
        $p->onRequestBeforeSend($this->getEvent());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testOnRequestBeforeSend_BadTimestampCallback()
    {
        $config = $this->config;
        $config['timestamp_callback'] = 'string';

        $p = new WssePlugin($config);
        $p->onRequestBeforeSend($this->getEvent());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testOnRequestBeforeSend_BadTimestampCallbackResponse()
    {
        $config = $this->config;
        $config['timestamp_callback'] = function() { return 42; };

        $p = new WssePlugin($config);
        $p->onRequestBeforeSend($this->getEvent());
    }

    public function testOnRequestBeforeSend()
    {
        $event = $this->getEvent();
        $request = $event['request'];
        $dateRef = new \DateTime();
        $dateRef->setTimestamp($event['timestamp']);

        $p = $this->newPlugin();

        $this->assertNull($request->getHeader('X-WSSE'));

        $p->onRequestBeforeSend($event);

        $this->assertNotNull($header = $request->getHeader('X-WSSE'));

        $this->assertEquals(1, preg_match(
                '/UsernameToken Username="([^"]+)", PasswordDigest="([^"]+)", Nonce="([^"]+)", Created="([^"]+)"/',
                $header,
                $matches
            )
        );
        $this->assertEquals('john', $matches[1]);
        $this->assertEquals($p->digest('doe', $matches[3], $matches[4]), $matches[2]);
        $this->assertInternalType('string', $matches[3]);
        $this->assertEquals($dateRef->format('c'), $matches[4]);
    }

    public function testGenerateNonce()
    {
        $p = $this->newPlugin();
        $nonces = array();

        for ($i = 0; $i < 1000; $i++) {
            $nonces[] = $p->generateNonce($this->getRequest());
        }

        $this->assertEquals(1000, count(array_unique($nonces)));
    }
}
