<?php
namespace GuzzleHttp\Subscriber;

use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Maintains a list of requests and responses sent using a request or client
 */
class History implements SubscriberInterface, \IteratorAggregate, \Countable
{
    /** @var int The maximum number of requests to maintain in the history */
    private $limit;

    /** @var array Requests and responses that have passed through the plugin */
    private $transactions = [];

    public function __construct($limit = 10)
    {
        $this->limit = $limit;
    }

    public function getEvents()
    {
        return [
            'complete' => ['onComplete', RequestEvents::EARLY],
            'error'    => ['onError', RequestEvents::EARLY],
        ];
    }

    /**
     * Convert to a string that contains all request and response headers
     *
     * @return string
     */
    public function __toString()
    {
        $lines = array();
        foreach ($this->transactions as $entry) {
            $response = isset($entry['response']) ? $entry['response'] : '';
            $lines[] = '> ' . trim($entry['sent_request'])
                . "\n\n< " . trim($response) . "\n";
        }

        return implode("\n", $lines);
    }

    public function onComplete(CompleteEvent $event)
    {
        $this->add($event->getRequest(), $event->getResponse());
    }

    public function onError(ErrorEvent $event)
    {
        // Only track when no response is present, meaning this didn't ever
        // emit a complete event
        if (!$event->getResponse()) {
            $this->add($event->getRequest());
        }
    }

    /**
     * Returns an Iterator that yields associative array values where each
     * associative array contains the following key value pairs:
     *
     * - request: Representing the actual request that was received.
     * - sent_request: A clone of the request that will not be mutated.
     * - response: The response that was received (if available).
     *
     * @return \Iterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->transactions);
    }

    /**
     * Get all of the requests sent through the plugin.
     *
     * Requests can be modified after they are logged by the history
     * subscriber. By default this method will return the actual request
     * instances that were received. Pass true to this method if you wish to
     * get copies of the requests that represent the request state when it was
     * initially logged by the history subscriber.
     *
     * @param bool $asSent Set to true to get clones of the requests that have
     *                     not been mutated since the request was received by
     *                     the history subscriber.
     *
     * @return RequestInterface[]
     */
    public function getRequests($asSent = false)
    {
        return array_map(function ($t) use ($asSent) {
            return $asSent ? $t['sent_request'] : $t['request'];
        }, $this->transactions);
    }

    /**
     * Get the number of requests in the history
     *
     * @return int
     */
    public function count()
    {
        return count($this->transactions);
    }

    /**
     * Get the last request sent.
     *
     * Requests can be modified after they are logged by the history
     * subscriber. By default this method will return the actual request
     * instance that was received. Pass true to this method if you wish to get
     * a copy of the request that represents the request state when it was
     * initially logged by the history subscriber.
     *
     * @param bool $asSent Set to true to get a clone of the last request that
     *                     has not been mutated since the request was received
     *                     by the history subscriber.
     *
     * @return RequestInterface
     */
    public function getLastRequest($asSent = false)
    {
        return $asSent
            ? end($this->transactions)['sent_request']
            : end($this->transactions)['request'];
    }

    /**
     * Get the last response in the history
     *
     * @return ResponseInterface|null
     */
    public function getLastResponse()
    {
        return end($this->transactions)['response'];
    }

    /**
     * Clears the history
     */
    public function clear()
    {
        $this->transactions = array();
    }

    /**
     * Add a request to the history
     *
     * @param RequestInterface  $request  Request to add
     * @param ResponseInterface $response Response of the request
     */
    private function add(
        RequestInterface $request,
        ResponseInterface $response = null
    ) {
        $this->transactions[] = [
            'request'      => $request,
            'sent_request' => clone $request,
            'response'     => $response
        ];
        if (count($this->transactions) > $this->limit) {
            array_shift($this->transactions);
        }
    }
}
