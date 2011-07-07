<?php

namespace Guzzle\Service;

use Guzzle\Common\Event\AbstractSubject;

/**
 * Iterate over a paginated set of resources that requires subsequent paginated
 * calls in order to retrieve an entire set of resources from a service.
 *
 * Implements Iterator and can be used in a foreach loop.
 * {@link http://www.php.net/manual/en/spl.iterators.php}
 *
 * Signals emitted:
 *
 *  event         context    description
 *  -----         -------    -----------
 *  before_send   array      About to issue another command to get more results
 *  after_send    array      Issued another command to get more results
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class ResourceIterator extends AbstractSubject implements \Iterator, \Countable
{
    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var mixed Current iterator value
     */
    protected $current;

    /**
     * @var array
     */
    protected $resourceList;

    /**
     * @var int Current index in $resourceList
     */
    protected $currentIndex = -1;

    /**
     * @var int Current number of resources that have been iterated
     */
    protected $pos = -1;

    /**
     * @var string NextToken/Marker for a subsequent request
     */
    protected $nextToken;

    /**
     * @var int Total number of resources that have been iterated
     */
    protected $iteratedCount = 0;

    /**
     * @var int Maximum number of resources to fetch per request
     */
    protected $pageSize;

    /**
     * @var int Maximum number of resources to retrieve in total
     */
    protected $limit;

    /**
     * @var array Initial data passed to the constructor -- used with rewind()
     */
    protected $data = array();

    /**
     * This should only be invoked by a {@see ClientInterface} object.
     *
     * @param ClientInterface $client Client responsible for sending requests
     *
     * @param array $data Associative array of additional parameters, including
     *      any initial data to be iterated.
     *
     *      <ul>
     *      <li>page_size => Max number of results to retrieve per request.</li>
     *      <li>resources => Initial resources to associate with the iterator.</li>
     *      <li>next_token => The value used to mark the beginning of a subsequent result set.</li>
     *      </ul>
     */
    public function __construct(ClientInterface $client, array $data)
    {
        $this->client = $client;
        $this->data = $data;
        $this->limit = array_key_exists('limit', $data) ? $data['limit'] : -1;
        $this->pageSize = array_key_exists('page_size', $data) ? $data['page_size'] : false;
        $this->resourceList = array_key_exists('resources', $data) ? $data['resources'] : array();
        $this->nextToken = array_key_exists('next_token', $data) ? $data['next_token'] : false;
        $this->retrievedCount = count($this->resourceList);
        $this->onLoad();
    }

    /**
     * Get all of the resources as an array (be careful as this could issue a
     * large number of requests if no limit is specified)
     *
     * @param bool $rewind (optional) By default, rewind() will be called
     *
     * @return array
     */
    public function toArray($rewind = true)
    {
        if ($rewind) {
            $this->rewind();
        }

        return iterator_to_array($this, false);
    }

    /**
     * Return the current element.
     *
     * @return mixed Returns the current element.
     */
    public function current()
    {
        return $this->current;
    }

    /**
     * Return the total number of items that have been retrieved thus far.
     *
     * Implements Countable
     *
     * @return string Returns the total number of items retrieved.
     */
    public function count()
    {
        return $this->retrievedCount;
    }

    /**
     * Return the total number of items that have been iterated thus far.
     *
     * @return string Returns the total number of items iterated.
     */
    public function getPosition()
    {
        return $this->pos;
    }

    /**
     * Return the key of the current element.
     *
     * @return string Returns the current key.
     */
    public function key()
    {
        // @codeCoverageIgnoreStart
        return $this->currentIndex;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Rewind the Iterator to the first element.
     */
    public function rewind()
    {
        $this->currentIndex = -1;
        $this->pos = -1;
        $this->resourceList = $this->data['resources'];
        $this->nextToken = $this->data['next_token'];
        $this->retrievedCount = count($this->resourceList);
        $this->next();
    }

    /**
     * Check if there is a current element after calls to rewind() or next().
     *
     * @return bool Returns TRUE if the current element is valid or FALSE
     */
    public function valid()
    {
        return isset($this->resourceList)
               && $this->current
               && ($this->pos < $this->limit || $this->limit == -1)
               && ($this->currentIndex < count($this->resourceList) || $this->nextToken);
    }

    /**
     * Move forward to next element.
     *
     * If a request needs to be sent to retrieve more elements, two events will
     * be dispatched:
     *      before_send -- The context will be the currently loaded items
     *      after_send -- The context will be the newly loaded items
     */
    public function next()
    {
        $this->pos++;

        if (!isset($this->resourceList)
            || ++$this->currentIndex >= count($this->resourceList)
            && $this->nextToken
            && ($this->limit == -1 || $this->pos < $this->limit)) {
                $this->getEventManager()->notify('before_send', $this->resourceList);
                $this->sendRequest();
                $this->getEventManager()->notify('after_send', $this->resourceList);
        }

        $this->current = (array_key_exists($this->currentIndex, $this->resourceList))
            ? $this->resourceList[$this->currentIndex]
            : null;
    }

    /**
     * Retrieve the NextToken that can be used in other iterators.
     *
     * @return string Returns a NextToken
     */
    public function getNextToken()
    {
        return $this->nextToken;
    }

    /**
     * Send a request to retrieve the next page of results.
     * Hook for sublasses to implement.
     */
    abstract protected function sendRequest();

    /**
     * Returns the value that should be specified for the page size for
     * a request that will maintain any hard limits, but still honor the
     * specified pageSize if the number of items retrieved + pageSize < hard
     * limit
     *
     * @return int Returns the page size of the next request.
     */
    protected function calculatePageSize()
    {
        if ($this->limit == -1) {
            return $this->pageSize;
        } else if ($this->pos + $this->pageSize > $this->limit) {
            return $this->limit - $this->pos;
        } else {
            // @codeCoverageIgnoreStart
            return $this->pageSize;
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Called when the iterator is constructed.
     *
     * Hook for sub-classes to implement.
     */
    protected function onLoad()
    {
    }
}