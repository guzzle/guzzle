<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Event\AbstractTransferStatsEvent;
use Guzzle\Http\Event\RequestEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to automatically retry failed HTTP requests using a backoff strategy
 */
class BackoffPlugin implements EventSubscriberInterface
{
    /** @var RetryFilterInterface */
    protected $filter;

    /** @var callable */
    protected $delayFunc;

    /** @var int */
    protected $maxRetries;

    /**
     * @param RetryFilterInterface $filter     Filter used to determine whether or not to retry a request
     * @param callable             $delayFunc  Callable that accepts the number of retries and returns the amount of
     *                                         of time in seconds to delay.
     * @param int                  $maxRetries Maximum number of retries
     */
    public function __construct(
        RetryFilterInterface $filter,
        callable $delayFunc,
        $maxRetries = 3
    ) {
        $this->filter = $filter;
        $this->delayFunc = $delayFunc;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Retrieve a basic truncated exponential backoff plugin that will retry HTTP errors and cURL errors
     *
     * @param int   $maxRetries Maximum number of retries
     * @param array $httpCodes  HTTP response codes to retry
     * @param array $curlCodes  cURL error codes to retry
     *
     * @return self
     */
    public static function getExponentialBackoff(
        $maxRetries = 3,
        array $httpCodes = null,
        array $curlCodes = null
    ) {
        return new self(
            new HttpStatusFilter(
                new CurlResultFilter($curlCodes),
                $httpCodes
            ),
            new ExponentialDelay(),
            $maxRetries
        );
    }

    public static function getSubscribedEvents()
    {
        return [
            RequestEvents::AFTER_SEND => 'onRequestSent',
            RequestEvents::ERROR      => 'onRequestSent'
        ];
    }

    public function onRequestSent(AbstractTransferStatsEvent $event)
    {
        $retries = (int) $event->getRequest()->getConfig()->get('retries');
        if ($retries < $this->maxRetries && $this->filter->shouldRetry($retries, $event)) {
            $request = $event->getRequest();
            sleep(call_user_func($this->delayFunc, $retries));
            $request->getConfig()->set('retries', ++$retries);
            $event->intercept($event->getClient()->send($request));
        }
    }
}
