<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\Plugin\DigestAuthPlugin;
use Guzzle\Http\Client;

/**
 * @covers Guzzle\Http\Plugin\DigestAuthPlugin
 */
class DigestAuthPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testAddsDigestAuthentication()
    {
        $plugin = new DigestAuthPlugin('julian', 'test');
        $client = new Client('http://www.test.com/');
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get('/');
        $this->assertEquals('julian', $request->getUsername());
        $this->assertEquals('test', $request->getPassword());
        
        $scheme = $request->getCurlOptions()->get(CURLOPT_HTTPAUTH);
        $this->assertEquals(CURLAUTH_DIGEST, $scheme, "digest scheme should have been set on the request");
    }
}