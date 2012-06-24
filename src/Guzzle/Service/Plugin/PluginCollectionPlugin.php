<?php

namespace Guzzle\Service\Plugin;

use Guzzle\Common\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Service builder plugin used to add plugins to all clients created by a
 * {@see Guzzle\Service\Builder\ServiceBuilder}
 *
 * @author Gordon Franke <info@nevalon.de>
 */
class PluginCollectionPlugin implements EventSubscriberInterface
{
    /**
     * @var $plugins array plugins to add
     */
    private $plugins = array();

    /**
     * @param array $plugins plugins to add
     */
    public function __construct(array $plugins)
    {
        $this->plugins = $plugins;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'service_builder.create_client' => 'onCreateClient'
        );
    }

    /**
     * Adds plugins to clients as they are created by the service builder
     *
     * @param Event $event Event emitted
     */
    public function onCreateClient(Event $event)
    {
        foreach ($this->plugins as $plugin) {
            $event['client']->addSubscriber($plugin);
        }
    }
}
