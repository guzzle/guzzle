<?php

namespace Guzzle\Plugin\Mock;

use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Http\Event\RequestBeforeSendEvent;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues mock responses or exceptions and delivers mock responses or exceptions in a fifo order.
 */
class MockPlugin extends AbstractHasDispatcher implements EventSubscriberInterface, \Countable
{
    /** @var array Array of mock responses / exceptions */
    private $queue = [];

    /** @var bool Whether or not to consume an entity body when a mock response is served */
    private $readBodies;

    /**
     * @param array $items      Array of responses or exceptions to queue
     * @param bool  $readBodies Set to false to not consume the entity body of a request when a mock is served
     */
    public function __construct(array $items = [], $readBodies = true)
    {
        $this->readBodies = $readBodies;
        $this->addMultiple($items);
    }

    public static function getSubscribedEvents()
    {
        return ['request.before_send' => ['onRequestBeforeSend', -999]];
    }

    public function onRequestBeforeSend(RequestBeforeSendEvent $event)
    {
        if ($this->queue) {
            $item = array_shift($this->queue);
            $request = $event->getRequest();
            // Emulate the receiving of the response headers
            $request->dispatch(RequestEvents::GOT_HEADERS, ['request' => $request, 'response' => $item]);
            // Emulate reading a response body
            if ($item instanceof ResponseInterface && $this->readBodies && $request->getBody()) {
                while (!$request->getBody()->eof()) {
                    $request->getBody()->read(8096);
                }
            }
            $event->intercept($item);
        }
    }

    public function count()
    {
        return count($this->queue);
    }

    /**
     * Add a response to the end of the queue
     *
     * @param string|ResponseInterface $response Response object or path to response file
     *
     * @return self
     * @throws \InvalidArgumentException if a string or Response is not passed
     */
    public function addResponse($response)
    {
        if (is_string($response)) {
            $response = file_exists($response)
                ? Response::fromMessage(file_get_contents($response))
                : Response::fromMessage($response);
        } elseif (!($response instanceof ResponseInterface)) {
            throw new \InvalidArgumentException('Response must a message string, response object, or path to a file');
        }

        $this->queue[] = $response;

        return $this;
    }

    /**
     * Add an exception to the end of the queue
     *
     * @param RequestException $e Exception to throw when the request is executed
     *
     * @return self
     */
    public function addException(RequestException $e)
    {
        $this->queue[] = $e;

        return $this;
    }

    /**
     * Add multiple items to the queue
     *
     * @param array $items Items to add
     */
    public function addMultiple(array $items)
    {
        foreach ($items as $item) {
            if ($item instanceof RequestException) {
                $this->addException($item);
            } else {
                $this->addResponse($item);
            }
        }
    }

    /**
     * Clear the queue
     */
    public function clearQueue()
    {
        $this->queue = [];
    }
}
