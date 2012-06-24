<?php

namespace Guzzle\Tests\Service;

use Guzzle\Service\Builder\ServiceBuilder;
use Guzzle\Service\Plugin\PluginCollectionPlugin;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginCollectionPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Plugin\PluginCollectionPlugin
     */
    public function testPluginPassPluginsThroughToClients()
    {
        $s = new ServiceBuilder(array(
            'michael.mock' => array(
                'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
                'params' => array(
                    'base_url' => 'http://www.test.com/',
                    'subdomain' => 'michael',
                    'password' => 'test',
                    'username' => 'michael',
                )
            )
        ));

        $plugin = $this->getMock('Symfony\Component\EventDispatcher\EventSubscriberInterface');

        $plugin::staticExpects($this->any())
             ->method('getSubscribedEvents')
             ->will($this->returnValue(array('client.create_request' => 'onRequestCreate')));

        $s->addSubscriber(new PluginCollectionPlugin(array($plugin)));

        $c = $s->get('michael.mock');
        $this->assertTrue($c->getEventDispatcher()->hasListeners('client.create_request'));

        $listeners = $c->getEventDispatcher()->getListeners('client.create_request');
        $this->assertSame($plugin, $listeners[0][0]);
        $this->assertEquals('onRequestCreate', $listeners[0][1]);
    }
}
