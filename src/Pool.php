<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Future\FutureInterface;
use GuzzleHttp\Event\ListenerAttacherTrait;
use GuzzleHttp\Event\EndEvent;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Sends and iterator of requests concurrently using a capped pool size.
 *
 * The Pool object implements FutureInterface, meaning it can be used later
 * when necessary, the requests provided to the pool can be cancelled, and
 * you can check the state of the pool to know if it has been dereferenced
 * (sent) or has been cancelled.
 *
 * When sending the pool, keep in mind that no results are returned: callers
 * are expected to handle results asynchronously using Guzzle's event system.
 * When requests complete, more are added to the pool to ensure that the
 * requested pool size is always filled as much as possible.
 *
 * IMPORTANT: Do not provide a pool size greater that what the utilized
 * underlying RingPHP handler can support. This will result is extremely poor
 * performance.
 */
class Pool implements FutureInterface
{
    use ListenerAttacherTrait;

    /** @var \GuzzleHttp\ClientInterface */
    private $client;

    /** @var \Iterator Yields requests */
    private $iter;

    /** @var Deferred */
    private $deferred;

    /** @var PromiseInterface */
    private $promise;

    private $waitQueue = [];
    private $eventListeners = [];
    private $poolSize;
    private $isRealized = false;

    /**
     * The option values for 'before', 'after', and 'error' can be a callable,
     * an associative array containing event data, or an array of event data
     * arrays. Event data arrays contain the following keys:
     *
     * - fn: callable to invoke that receives the event
     * - priority: Optional event priority (defaults to 0)
     * - once: Set to true so that the event is removed after it is triggered
     *
     * @param ClientInterface $client   Client used to send the requests.
     * @param array|\Iterator $requests Requests to send in parallel
     * @param array           $options  Associative array of options
     *     - pool_size: (int) Maximum number of requests to send concurrently
     *     - before:    (callable|array) Receives a BeforeEvent
     *     - after:     (callable|array) Receives a CompleteEvent
     *     - error:     (callable|array) Receives a ErrorEvent
     */
    public function __construct(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        $this->client = $client;
        $this->iter = $this->coerceIterable($requests);
        $this->deferred = new Deferred();
        $this->promise = $this->deferred->promise();
        $this->poolSize = isset($options['pool_size'])
            ? $options['pool_size'] : 25;
        $this->eventListeners = $this->prepareListeners(
            $options,
            ['before', 'complete', 'error', 'end']
        );
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
     * @return BatchResults Returns a container for the results.
     * @throws \InvalidArgumentException if the event format is incorrect.
     */
    public static function batch(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        $hash = new \SplObjectStorage();
        foreach ($requests as $request) {
            $hash->attach($request);
        }

        // In addition to the normally run events when requests complete, add
        // and event to continuously track the results of transfers in the hash.
        (new self($client, $requests, RequestEvents::convertEventArray(
            $options,
            ['end'],
            [
                'priority' => RequestEvents::LATE,
                'fn'       => function (EndEvent $e) use ($hash) {
                    $hash[$e->getRequest()] = $e->getException()
                        ? $e->getException()
                        : $e->getResponse();
                }
            ]
        )))->wait();

        return new BatchResults($hash);
    }

    /**
     * Creates a Pool and immediately sends the requests.
     *
     * @param ClientInterface $client   Client used to send the requests
     * @param array|\Iterator $requests Requests to send in parallel
     * @param array           $options  Passes through the options available in
     *                                  {@see GuzzleHttp\Pool::__construct}
     */
    public static function send(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        (new self($client, $requests, $options))->wait();
    }

    public function wait()
    {
        if ($this->isRealized) {
            return false;
        }

        // Seed the pool with N number of requests.
        for ($i = 0; $i < $this->poolSize; $i++) {
            if (!$this->addNextRequest()) {
                break;
            }
        }

        // Stop if the pool was cancelled while transferring requests.
        if ($this->isRealized) {
            return false;
        }

        // Wait on any outstanding FutureResponse objects.
        while ($response = array_pop($this->waitQueue)) {
            try {
                $response->wait();
            } catch (\Exception $e) {
                // Eat exceptions because they should be handled asynchronously
            }
        }

        // Clean up no longer needed state.
        $this->isRealized = true;
        $this->waitQueue = $this->eventListeners = [];
        $this->client = $this->iter = null;
        $this->deferred->resolve(true);

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Attempt to cancel all outstanding requests (requests that are queued for
     * dereferencing). Returns true if all outstanding requests can be
     * cancelled.
     *
     * @return bool
     */
    public function cancel()
    {
        if ($this->isRealized) {
            return false;
        }

        $success = $this->isRealized = true;
        foreach ($this->waitQueue as $response) {
            if (!$response->cancel()) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Returns a promise that is invoked when the pool completed. There will be
     * no passed value.
     *
     * {@inheritdoc}
     */
    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null,
        callable $onProgress = null
    ) {
        return $this->promise->then($onFulfilled, $onRejected, $onProgress);
    }

    public function promise()
    {
        return $this->promise;
    }

    private function coerceIterable($requests)
    {
        if ($requests instanceof \Iterator) {
            return $requests;
        } elseif (is_array($requests)) {
            return new \ArrayIterator($requests);
        }

        throw new \InvalidArgumentException('Expected Iterator or array. '
            . 'Found ' . Core::describeType($requests));
    }

    /**
     * Adds the next request to pool and tracks what requests need to be
     * dereferenced when completing the pool.
     */
    private function addNextRequest()
    {
        if ($this->isRealized || !$this->iter || !$this->iter->valid()) {
            return false;
        }

        $request = $this->iter->current();
        $this->iter->next();

        if (!($request instanceof RequestInterface)) {
            throw new \InvalidArgumentException(sprintf(
                'All requests in the provided iterator must implement '
                . 'RequestInterface. Found %s',
                Core::describeType($request)
            ));
        }

        // Be sure to use "lazy" futures, meaning they do not send right away.
        $request->getConfig()->set('future', 'lazy');
        $this->attachListeners($request, $this->eventListeners);
        $response = $this->client->send($request);
        $hash = spl_object_hash($request);
        $this->waitQueue[$hash] = $response;

        // Use this function for both resolution and rejection.
        $fn = function ($value) use ($request, $hash) {
            unset($this->waitQueue[$hash]);
            $result = $value instanceof ResponseInterface
                ? ['request' => $request, 'response' => $value, 'error' => null]
                : ['request' => $request, 'response' => null, 'error' => $value];
            $this->deferred->progress($result);
            $this->addNextRequest();
        };

        $response->then($fn, $fn);

        return true;
    }
}
