<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds HTTP basic auth to all requests sent from a client
 */
class BasicAuthPlugin implements EventSubscriberInterface
{
    private $username;
    private $password;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array('client.create_request' => 'onRequestCreate');
    }

    /**
     * Constructor
     *
     * @param string $username HTTP basic auth username
     * @param string $password Password
     */
    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Add basic auth
     *
     * @param Event $event
     */
    public function onRequestCreate(Event $event)
    {
        $request = $event['request'];
        $request->setAuth($this->username, $this->password);
    }
}