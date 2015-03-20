<?php
namespace GuzzleHttp\Handler;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Handler that returns a canned response or evaluated function result.
 */
class MockHandler
{
    /** @var array|PromiseInterface|ResponseInterface|callable */
    private $result;

    /**
     * Provide a Response, Promise, or Exception to always return the same
     * value. Provide a callable that accepts a request object and request
     * options and returns a Response, Promise, or Exception. Provide an array
     * to have the mock handler return responses or throw exceptions using a
     * queue of responses.
     *
     * @param mixed $resultOrQueue Response, queue, exception, or callable.
     */
    public function __construct($resultOrQueue)
    {
        $this->result = !is_callable($resultOrQueue) && is_array($resultOrQueue)
            ? $this->createQueueFn($resultOrQueue)
            : $resultOrQueue;
    }

    public function __invoke(RequestInterface $request, array $options)
    {
        if (isset($options['delay'])) {
            usleep($options['delay'] * 1000);
        }

        $response = is_callable($this->result)
            ? call_user_func($this->result, $request)
            : $this->result;

        if ($response instanceof \Exception) {
            return new RejectedPromise($response);
        } elseif ($response instanceof PromiseInterface) {
            return $response;
        }

        return new FulfilledPromise($response);
    }

    private function createQueueFn(array $queue)
    {
        return function () use (&$queue) {
            if (empty($queue)) {
                throw new \RuntimeException('Mock queue is empty');
            }

            return array_shift($queue);
        };
    }
}
