<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Ring\FutureInterface;
use GuzzleHttp\Event\ListenerAttacherTrait;

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
 * requested pool size is always filled when possible.
 */
class Pool implements FutureInterface
{
    use ListenerAttacherTrait;

    /** @var \GuzzleHttp\ClientInterface */
    private $client;

    /** @var array Hash of outstanding responses */
    private $derefQueue = [];

    /** @var \Iterator Yields requests */
    private $iter;

    /** @var int */
    private $poolSize;

    /** @var bool */
    private $isCancelled = false;

    /** @var array Listeners to attach to each request */
    private $eventListeners = [];

    /**
     * Sends multiple requests concurrently using a fixed pool size.
     *
     * Exceptions are not thrown for failed requests. Callers are expected to
     * register an "error" option to handle request errors OR directly register
     * an event handler for the "error" event of a request's
     * event emitter.
     *
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
     *     - parallel: (int) Maximum number of requests to send in parallel
     *     - before:   (callable|array) Receives a BeforeEvent
     *     - after:    (callable|array) Receives a CompleteEvent
     *     - error:    (callable|array) Receives a ErrorEvent
     */
    public function __construct(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        $this->client = $client;
        $this->iter = $this->coerceIterable($requests);
        $this->poolSize = isset($options['pool_size'])
            ? $options['pool_size']
            : Client::DEFAULT_CONCURRENCY;
        $this->eventListeners = $this->prepareListeners(
            $this->prepareOptions($options),
            ['before', 'complete', 'error']
        );
    }

    /**
     * Convenience method for creating and immediately dereferencing a pool.
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
            if ($response instanceof FutureInterface) {
                $response->deref();
            }
        }

        $this->iter = $this->derefQueue = null;

        return true;
    }

    public function cancelled()
    {
        return $this->isCancelled;
    }

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

    private function coerceIterable($requests)
    {
        if ($requests instanceof \Iterator) {
            return $requests;
        } elseif (is_array($requests)) {
            return new \ArrayIterator($requests);
        } else {
            throw new \InvalidArgumentException('Expected Iterator or array');
        }
    }

    private function prepareOptions(array $options)
    {
        // Remove requests from the deref queue on complete and send another.
        $options = RequestEvents::convertEventArray($options,
            ['complete', 'error'],
            [
                'priority' => RequestEvents::EARLY,
                'fn'       => function ($e) {
                    unset($this->derefQueue[spl_object_hash($e->getRequest())]);
                    $this->addNextRequest();
                }
            ]
        );

        // Stop error events from raising
        return RequestEvents::convertEventArray($options, ['error'], [
            'priority' => RequestEvents::LATE - 1,
            'fn'       => function (ErrorEvent $e) {
                $e->stopPropagation();
            }
        ]);
    }

    private function addNextRequest()
    {
        if ($this->isCancelled || !$this->iter->valid()) {
            return false;
        }

        $request = $this->iter->current();
        $this->iter->next();

        if (!($request instanceof RequestInterface)) {
            $found = is_object($request)
                ? get_class($request)
                : gettype($request);
            $err = sprintf('All requests in the provided iterator must '
                . 'implement RequestInterface. Found %s', $found);
            throw new \RuntimeException($err);
        }

        $request->getConfig()->set('future', 'batch');
        $this->attachListeners($request, $this->eventListeners);
        $this->derefQueue[spl_object_hash($request)] = $this->client->send($request);

        return true;
    }
}
