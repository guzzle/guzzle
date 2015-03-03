<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    /** @var callable[] */
    private $thenFns;

    /**
     * @param ClientInterface $client   Client used to send the requests.
     * @param array|\Iterator $requests Requests to send concurrently.
     * @param array           $options  Associative array of options
     *     - pool_size: (int) Maximum number of requests to send concurrently
     *     - request_options: Array of request options to apply to each.
     *     - then: (callable[]) Array containing an optional onFulfilled then
     *       function to invoke after each response completes (null to omit)
     *       and an onRejected function to invoke after each failure (null to
     *       omit, or do not pass element 1 in the array).
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
        if (!empty($options['then'])) {
            if (count($options['then']) == 1) {
                $options['then'][] = null;
            }
            $this->thenFns = $options['then'];
        } else {
            $this->thenFns = null;
        }

        parent::__construct(function () {
            // Seed the pool with N number of requests.
            while ($this->pending || $this->addNextRequests()) {
                array_pop($this->pending)->wait(false);
            }
            $this->resolve(true);
        });
    }

    /**
     * Sends multiple requests in parallel and returns an array of responses
     * and exceptions that uses the same ordering as the provided requests.
     *
     * IMPORTANT: This method keeps every request and response in memory, and
     * as such, is NOT recommended when sending a large number or an
     * indeterminate number of requests concurrently.
     *
     * @param ClientInterface $client   Client used to send the requests
     * @param array|\Iterator $requests Requests to send in parallel
     * @param array           $options  Passes through the options available in
     *                                  {@see GuzzleHttp\Pool::__construct}
     *
     * @return array Returns an array containing the response or an exception
     *               in the same order that the requests were sent.
     * @throws \InvalidArgumentException if the event format is incorrect.
     */
    public static function batch(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        $results = [];
        $options['then'] = [
            function (ResponseInterface $response, $index) use (&$results) {
                $results[$index] = $response;
            },
            function ($reason, $index) use (&$results) {
                $results[$index] = $reason;
            }
        ];
        $pool = new static($client, $requests, $options);
        $pool->wait();

        return $results;
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
            . 'Found ' . Utils::describeType($requests));
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
                return false;
            }
        }

        return true;
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

        $index = $this->iter->key();
        $request = $this->iter->current();
        $this->iter->next();

        if (is_callable($request)) {
            $response = $request($this->requestOptions);
        } elseif (!($request instanceof RequestInterface)) {
            throw new \InvalidArgumentException(sprintf(
                'All requests in the provided iterator must implement '
                . 'RequestInterface. Found %s',
                Utils::describeType($request)
            ));
        } else {
            $response = $this->client->send($request, $this->requestOptions);
        }

        if ($this->thenFns) {
            $this->callThens($response, $index);
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

    private function callThens(ResponsePromiseInterface $response, $index)
    {
        $fns = [null, null];

        if (isset($this->thenFns[0])) {
            $fns[0] = function (ResponseInterface $response) use ($index) {
                call_user_func($this->thenFns[0], $response, $index);
            };
        }

        if (isset($this->thenFns[1])) {
            $fns[1] = function ($reason) use ($index) {
                call_user_func($this->thenFns[1], $reason, $index);
            };
        }

        $response->then($fns[0], $fns[1]);
    }
}
