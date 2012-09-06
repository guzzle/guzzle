<?php

namespace Guzzle\Plugin\History;

use Guzzle\Common\Event;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maintains a list of requests and responses sent using a request or client
 */
class HistoryPlugin implements EventSubscriberInterface, \IteratorAggregate, \Countable
{
    /**
     * @var int The maximum number of requests to maintain in the history
     */
    protected $limit = 10;

    /**
     * @var array Requests that have passed through the plugin
     */
    protected $requests = array();

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array('request.complete' => 'onRequestComplete');
    }

    /**
     * Add a request to the history
     *
     * @param RequestInterface $request Request to add
     *
     * @return HistoryPlugin
     */
    public function add(RequestInterface $request)
    {
        if ($request->getResponse()) {
            $this->requests[] = $request;
            if (count($this->requests) > $this->getlimit()) {
                array_shift($this->requests);
            }
        }

        return $this;
    }

    /**
     * Set the max number of requests to store
     *
     * @param int $limit Limit
     *
     * @return HistoryPlugin
     */
    public function setLimit($limit)
    {
        $this->limit = (int) $limit;

        return $this;
    }

    /**
     * Get the request limit
     *
     * @return int
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * Get the requests in the history
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->requests);
    }

    /**
     * Get the number of requests in the history
     *
     * @return int
     */
    public function count()
    {
        return count($this->requests);
    }

    /**
     * Get the last request sent
     *
     * @return RequestInterface
     */
    public function getLastRequest()
    {
        return end($this->requests);
    }

    /**
     * Get the last response in the history
     *
     * @return Response
     */
    public function getLastResponse()
    {
        return $this->getLastRequest()->getResponse();
    }

    /**
     * Clears the history
     *
     * @return HistoryPlugin
     */
    public function clear()
    {
        $this->requests = array();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function onRequestComplete(Event $event)
    {
        $this->add($event['request']);
    }
}
