<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;

/**
 * Sends and iterator of requests concurrently using a capped pool size.
 *
 * The pool will read from an iterator until it is cancelled or until the
 * iterator is consumed. When a request is yielded, the request is sent after
 * applying the "request_options" request options (if provided in the ctor).
 * When a function is yielded, the function is provided the "request_options"
 * array that should be merged on top of any existing options, and
 * the function MUST then return a wait-able promise.
 */
class Pool extends Promise implements PromiseInterface
{
    /** @var ClientInterface */
    private $client;

    /** @var \Iterator Yields requests */
    private $iter;

    /** @var int|callable */
    private $poolSize;

    /** @var array */
    private $pending = [];

    /** @var array */
    private $requestOptions;

    /**
     * @param ClientInterface $client   Client used to send the requests.
     * @param array|\Iterator $requests Requests to send concurrently.
     * @param array           $options  Associative array of options
     *     - pool_size: (int) Maximum number of requests to send concurrently
     *     - request_options: Array of request options to apply to each.
     */
    public function __construct(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        $this->client = $client;
        $this->iter = $this->coerceIterable($requests);
        $this->poolSize = isset($options['pool_size'])
            ? $options['pool_size'] : 25;
        $this->requestOptions = isset($options['request_options'])
            ? $options['request_options']
            : [];

        parent::__construct(function () {
            // Seed the pool with N number of requests.
            $this->addNextRequests();
            while ($this->pending) {
                array_pop($this->pending)->wait(false);
                $this->addNextRequests();
            }
            $this->resolve(true);
        });
    }

    /**
     * @param $requests
     * @return \Iterator
     */
    private function coerceIterable($requests)
    {
        if ($requests instanceof \Iterator) {
            return $requests;
        } elseif (is_array($requests)) {
            return new \ArrayIterator($requests);
        }

        throw new \InvalidArgumentException('Expected Iterator or array.'
            . 'Found ' . describe_type($requests));
    }

    private function getPoolSize()
    {
        return is_callable($this->poolSize)
            ? call_user_func($this->poolSize, count($this->pending))
            : $this->poolSize;
    }

    /**
     * Add as many requests as possible up to the current pool limit.
     */
    private function addNextRequests()
    {
        $limit = max($this->getPoolSize() - count($this->pending), 0);
        while ($limit--) {
            if (!$this->addNextRequest()) {
                break;
            }
        }
    }

    /**
     * Adds the next request to pool and tracks what requests need to be
     * dereferenced when completing the pool.
     */
    private function addNextRequest()
    {
        add_next:
        if ($this->getState() !== 'pending' || !$this->iter->valid()) {
            return false;
        }

        $request = $this->iter->current();
        $this->iter->next();

        if (is_callable($request)) {
            $response = $request($this->requestOptions);
        } elseif (!($request instanceof RequestInterface)) {
            throw new \InvalidArgumentException(sprintf(
                'All requests in the provided iterator must implement '
                . 'RequestInterface. Found %s',
                describe_type($request)
            ));
        } else {
            $response = $this->client->send($request, $this->requestOptions);
        }

        if ($response->getState() !== 'pending') {
            goto add_next;
        }

        $this->pending[spl_object_hash($response)] = $response;
        $fn = function () use ($response) {
            unset($this->pending[spl_object_hash($response)]);
            $this->addNextRequests();
        };

        $response->then($fn, $fn);

        return true;
    }
}
