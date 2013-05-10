<?php

namespace Guzzle\Http\Message;

use Guzzle\Iterator\MapIterator;

/**
 * Maintains a text-based history of redirects for a transaction
 */
class RedirectHistory implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /**
     * @var array Array of 'request' and 'response' keys
     */
    protected $history = array();

    /**
     * Returns the transaction as a string similar to curl's verbose output
     *
     * @return string
     */
    public function __toString()
    {
        $lines = array();
        foreach ($this->history as $r) {
            $lines[] = '> ' . $r['request'] . "\n\n< " . $r['response'] . "\n\n";
        }

        return implode("* Sending redirect request\n", $lines);
    }

    /**
     * Add a transaction to the history
     *
     * @param RequestInterface $request  Request to add
     * @param Response         $response Response of the request
     *
     * @return int Returns the index position of this transaction
     */
    public function addTransaction(RequestInterface $request, Response $response = null)
    {
        $this->history[] = array(
            'request'  => trim($request),
            'response' => trim($response)
        );

        return count($this->history) - 1;
    }

    /**
     * Set the response of a previously added transaction
     *
     * @param int      $index    Index of the request
     * @param Response $response Response to set
     */
    public function setTransactionResponse($index, Response $response)
    {
        $this->history[$index]['response'] = trim($response);
    }

    /**
     * Get the effective URL of the transaction (the last redirected URL)
     *
     * @return string|null
     */
    public function getEffectiveUrl()
    {
        $url = null;
        if ($last = end($this->history)) {
            $url = RequestFactory::getInstance()->fromMessage($last['request'])->getUrl();
        }

        return $url;
    }

    /**
     * Returns an iterator that yields request objects for the history rather than strings
     *
     * @return \Iterator
     */
    public function iterateObjects()
    {
        return new MapIterator($this->getIterator(), function ($entry) {
            $request = RequestFactory::getInstance()->fromMessage($entry['request']);
            if ($entry['response']) {
                $request->setResponse(Response::fromMessage($entry['response']));
            }
            return $request;
        });
    }

    /**
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->history);
    }

    public function count()
    {
        return count($this->history);
    }

    public function offsetGet($offset)
    {
        return isset($this->history[$offset]) ? $this->history[$offset] : null;
    }

    public function offsetExists($offset)
    {
        return isset($this->history[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->history[$offset]);
    }

    public function offsetSet($offset, $value)
    {
        $this->history[$offset] = $value;
    }
}
