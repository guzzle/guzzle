<?php

namespace Guzzle\Http\Pool;

use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Event\RequestEvents;

/**
 * Decorates a PoolInterface object and passes exceptions to a callable rather
 * than throwing them as they occur.
 */
class ExceptionHandlerPool implements PoolInterface
{
    /** @var callable */
    private $handler;

    /** @var PoolInterface */
    private $pool;

    /**
     * @param PoolInterface $pool    Pool to wrap and handle exceptions
     * @param callable      $handler Method to invoke when an exception occurs.
     *                               The method will receive a RequestException.
     */
    public function __construct(PoolInterface $pool, callable $handler)
    {
        $this->pool = $pool;
        $this->handler = $handler;
    }

    public function send($requests)
    {
        // Store a list of failed requests
        $failed = new \SplObjectStorage();
        $filtered = $this->getFilteredRequests($requests, $failed);

        foreach ($this->pool->send($filtered) as $request => $response) {
            if (!$failed->contains($request)) {
                // Only yield successful requests
                yield $request => $response;
            } else {
                // Remove requests from the failed list after passing
                $failed->detach($request);
            }
        }
    }

    private function getFilteredRequests($requests, \SplObjectStorage $failed)
    {
        // Wrap the passed in handler to stop event propagation, thereby preventing exceptions
        $handler = function (RequestErrorEvent $event) use ($failed) {
            $event->stopPropagation();
            // Mark the request so that it isn't later yielded
            $failed->attach($event->getRequest());
            call_user_func($this->handler, $event);
        };

        // While iterating through the given requests, add the custom listener
        $filtered = function ($requests) use ($handler) {
            foreach ($requests as $request) {
                $request->getEventDispatcher()->addListener(
                    RequestEvents::ERROR,
                    $handler
                );
                yield $request;
            }
        };

        return $filtered($requests);
    }
}
