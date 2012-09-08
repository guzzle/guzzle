<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Exception\HttpException;

/**
 * Strategy that will invoke a closure to determine whether or not to retry with a delay
 */
class CallbackBackoffStrategy extends AbstractBackoffStrategy
{
    /**
     * @var \Closure|array|mixed Callable method to invoke
     */
    protected $callback;

    /**
     * @param \Closure|array|mixed     $callback Callable method to invoke
     * @param BackoffStrategyInterface $next     The optional next strategy
     */
    public function __construct($callback, BackoffStrategyInterface $next = null)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('The callback must be callable');
        }
        $this->callback = $callback;
        $this->next = $next;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDelay($retries, RequestInterface $request, Response $response = null, HttpException $e = null)
    {
        $callback = $this->callback;

        return $callback($retries, $request, $response, $e);
    }
}
