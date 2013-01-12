<?php

namespace Guzzle\Http;

use Guzzle\Common\Event;
use Guzzle\Common\HasDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * EntityBody decorator that emits events for read and write methods
 */
class IoEmittingEntityBody extends AbstractEntityBodyDecorator implements HasDispatcherInterface
{
    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array('body.read', 'body.write');
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventDispatcher()
    {
        if (!$this->eventDispatcher) {
            $this->eventDispatcher = new EventDispatcher();
        }

        return $this->eventDispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($eventName, array $context = array())
    {
        $this->getEventDispatcher()->dispatch($eventName, new Event($context));
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        $this->getEventDispatcher()->addSubscriber($subscriber);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function read($length)
    {
        $event = array(
            'body'   => $this,
            'length' => $length,
            'read'   => $this->body->read($length)
        );
        $this->dispatch('body.read', $event);

        return $event['read'];
    }

    /**
     * {@inheritdoc}
     */
    public function write($string)
    {
        $event = array(
            'body'   => $this,
            'write'  => $string,
            'result' => $this->body->write($string)
        );
        $this->dispatch('body.write', $event);

        return $event['result'];
    }
}
