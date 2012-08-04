<?php

namespace Guzzle\Service\Resource;

use Guzzle\Common\HasDispatcherInterface;

/**
 * Iterate over a paginated set of resources that requires subsequent paginated
 * calls in order to retrieve an entire set of resources from a service.
 */
interface ResourceIteratorInterface extends HasDispatcherInterface, \Iterator, \Countable
{
    /**
     * Get all of the resources as an array (be careful as this could issue a
     * large number of requests if no limit is specified)
     *
     * @return array
     */
    public function toArray();

    /**
     * Retrieve the NextToken that can be used in other iterators.
     *
     * @return string Returns a NextToken
     */
    public function getNextToken();

    /**
     * Attempt to limit the total number of resources returned by the iterator.
     *
     * You may still receive more items than you specify.  Set to 0 to specify
     * no limit.
     *
     * @param int $limit Limit amount
     *
     * @return ResourceIteratorInterface
     */
    public function setLimit($limit);

    /**
     * Attempt to limit the total number of resources retrieved per request by
     * the iterator.
     *
     * The iterator may return more than you specify in the page size argument
     * depending on the service and underlying command implementation.  Set to
     * 0 to specify no page size limitation.
     *
     * @param int $limit Limit amount
     *
     * @return ResourceIteratorInterface
     */
    public function setPageSize($pageSize);

    /**
     * Get a data option from the iterator
     *
     * @param string $key Key of the option to retrieve
     *
     * @return mixed|null Returns NULL if not set or the value if set
     */
    public function get($key);

    /**
     * Set a data option on the iterator
     *
     * @param string $key   Key of the option to set
     * @param mixed  $value Value to set for the option
     *
     * @return ResourceIteratorInterface
     */
    public function set($key, $value);
}
