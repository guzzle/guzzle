<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\FutureInterface;
use GuzzleHttp\Event\ListenerAttacherTrait;
use GuzzleHttp\Event\EndEvent;

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
 * underlying Guzzle Ring adapter can support. This will result is extremely
 * poor performance.
 */
class Pool implements FutureInterface
{
    use ListenerAttacherTrait;

    /** @var \GuzzleHttp\ClientInterface */
    private $client;

    /** @var array Hash of outstanding responses to dereference. */
    private $derefQueue = [];

    /** @var array Hash of completed responses. */
    private $completedQueue = [];

    /** @var \Iterator Yields requests */
    private $iter;

    /** @var int */
    private $poolSize;

    /** @var bool */
    private $isCancelled = false;

    /** @var array Listeners to attach to each request */
    private $eventListeners = [];

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
        $this->poolSize = isset($options['pool_size'])
            ? $options['pool_size'] : 25;
        $this->eventListeners = $this->prepareListeners(
            $this->prepareOptions($options),
            ['before', 'complete', 'error', 'end']
        );
    }

    /**
     * Creates and immediately transfers a Pool object.
     *
     * @param ClientInterface $client   Client used to send the requests.
     * @param array|\Iterator $requests Requests to send in parallel
     * @param array           $options  Associative array of options
     * @see GuzzleHttp\Pool::__construct for the list of available options.
     */
    public static function send(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        (new self($client, $requests, $options))->deref();
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
        static::send($client, $requests, RequestEvents::convertEventArray(
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
        ));

        return new BatchResults($hash);
    }

    public function realized()
    {
        return !$this->iter || $this->cancelled();
    }

    public function deref()
    {
        if ($this->realized()) {
            return false;
        }

        // Seed the pool with N number of requests.
        // @todo: Is there way to stop seeding when the adapter auto-flushes?
        for ($i = 0; $i < $this->poolSize; $i++) {
            if (!$this->addNextRequest()) {
                break;
            }
        }

        // Stop if the pool was cancelled while transferring requests.
        if ($this->isCancelled) {
            return false;
        }

        // Dereference any outstanding FutureResponse objects.
        while ($response = array_pop($this->derefQueue)) {
            $response->deref();
        }

        // Clean up no longer needed state.
        $this->client = $this->iter = $this->derefQueue = $this->eventListeners = null;

        return true;
    }

    public function cancelled()
    {
        return $this->isCancelled;
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
        if ($this->isCancelled || $this->realized()) {
            return false;
        }

        // Return true if ALL in-flight requests could be cancelled.
        $success = $this->isCancelled = true;
        foreach ($this->derefQueue as $response) {
            if (!$response->cancel()) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Given the input variable, return an iterator if possible or throw.
     *
     * @param mixed $requests
     *
     * @return \Iterator
     * @throws \InvalidArgumentException if unable to coerce into an iterable.
     */
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
     * Adds the necessary options to manage the pool.
     */
    private function prepareOptions(array $options)
    {
        // Add the next request when requests finish, and stop errors from
        // throwing by intercepting with a future that throws when accessed.
        return RequestEvents::convertEventArray($options, ['end'], [
            'priority' => RequestEvents::LATE - 1,
            'fn'       => function (EndEvent $e) {
                $hash = spl_object_hash($e->getRequest());
                // Remove from deref queue if present.
                if (isset($this->derefQueue[$hash])) {
                    unset($this->derefQueue[$hash]);
                } else {
                    // Add to the completed queue to prevent deref'ng
                    $this->completedQueue[$hash] = true;
                }
                // If there was an error, then prevent the exception.
                if ($e->getException()) {
                    RequestEvents::stopException($e);
                }
                // Add the next request in the pool when done with this one.
                $this->addNextRequest();
            }
        ]);
    }

    /**
     * Adds the next request to pool and tracks what requests need to be
     * dereferenced when completing the pool.
     */
    private function addNextRequest()
    {
        if ($this->isCancelled || !$this->iter->valid()) {
            return false;
        }

        $request = $this->iter->current();
        $this->iter->next();

        if (!($request instanceof RequestInterface)) {
            throw new \RuntimeException(sprintf(
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

        // Determine how to track the request based on what happened in events.
        if (isset($this->completedQueue[$hash])) {
            // Things in the completed queue were completed in events before
            // the future was returned from the client. This means there's no
            // need to dereference them when the pool finishes.
            unset($this->completedQueue[$hash]);
        } elseif ($response instanceof FutureResponse) {
            // Track future responses for later dereference before completing
            // pool.
            $this->derefQueue[$hash] = $response;
        }

        return true;
    }
}
