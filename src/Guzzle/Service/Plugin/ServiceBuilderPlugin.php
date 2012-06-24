<?php

namespace Guzzle\Service\Plugin;

use Guzzle\Common\Event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Service builder plugin to add plugins to all service clients
 *
 * @author Gordon Franke <info@nevalon.de>
 */
class ServiceBuilderPlugin implements EventSubscriberInterface
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
     * Add plugins when client whould create
     *
     * @param Event $event
     */
    public function onCreateClient(Event $event)
    {
        foreach ($this->plugins as $plugin) {
            $event['client']->addSubscriber($plugin);
        }
    }
}
