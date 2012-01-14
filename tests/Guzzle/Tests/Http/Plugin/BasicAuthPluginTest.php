<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\Plugin\BasicAuthPlugin;
use Guzzle\Http\Client;

/**
 * @covers Guzzle\Http\Plugin\BasicAuthPlugin
 */
class BasicAuthPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testAddsBasicAuthentication()
    {
        $plugin = new BasicAuthPlugin('michael', 'test');
        $client = new Client('http://www.test.com/');
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get('/');
        $this->assertEquals('michael', $request->getUsername());
        $this->assertEquals('test', $request->getPassword());
    }
}