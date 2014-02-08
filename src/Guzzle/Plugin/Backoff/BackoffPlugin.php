<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Common\EventSubscriberInterface;
use Guzzle\Http\Event\AbstractTransferStatsEvent;
use Guzzle\Http\Event\RequestEvents;

/**
 * Plugin to automatically retry failed HTTP requests using a backoff strategy
 */
class BackoffPlugin implements EventSubscriberInterface
{
    /** @var callable */
    private $filter;

    /** @var callable */
    private $delayFunc;

    /** @var int */
    private $maxRetries;

    /**
     * @param callable $filter    Filter used to determine whether or not to retry a request
     * @param callable $delayFunc Callable that accepts the number of retries and returns the amount of
     *                            of time in seconds to delay.
     * @param int                 $maxRetries Maximum number of retries
     */
    public function __construct(
        callable $filter,
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
        if (extension_loaded('curl')) {
            $filter = self::createChainFilter([
                self::createStatusFilter($httpCodes),
                self::createCurlFilter($curlCodes)
            ]);
        } else {
            $filter = self::createStatusFilter($httpCodes);
        }

        return new self($filter, array(__CLASS__, 'exponentialDelay'), $maxRetries);
    }

    public static function getSubscribedEvents()
    {
        return [
            RequestEvents::AFTER_SEND => ['onRequestSent'],
            RequestEvents::ERROR      => ['onRequestSent']
        ];
    }

    public function onRequestSent(AbstractTransferStatsEvent $event)
    {
        $request = $event->getRequest();
        $retries = (int) $request->getConfig()->get('retries');

        if ($retries < $this->maxRetries) {
            $filterFn = $this->filter;
            if ($filterFn($retries, $event)) {
                $delayFn = $this->delayFunc;
                sleep($delayFn($retries));
                $request->getConfig()->set('retries', ++$retries);
                $event->intercept($event->getClient()->send($request));
            }
        }
    }

    /**
     * Returns an exponential delay calculation
     *
     * @param int $retries Number of retries so far
     *
     * @return int
     */
    public static function exponentialDelay($retries)
    {
        return (int) pow(2, $retries - 1);
    }

    /**
     * Creates a retry filter based on HTTP status codes
     *
     * @param array $failureStatuses Pass an array of status codes to override the default of [500, 503]
     *
     * @return callable
     */
    public static function createStatusFilter(array $failureStatuses = null)
    {
        $failureStatuses = $failureStatuses ?: [500, 503];
        $failureStatuses = array_fill_keys($failureStatuses, 1);

        return function ($retries, AbstractTransferStatsEvent $event) use ($failureStatuses) {
            if (!($response = $event->getResponse())) {
                return false;
            }
            return isset($failureStatuses[$response->getStatusCode()]);
        };
    }

    /**
     * Creates a retry filter based on cURL error codes.
     *
     * @param array $errorCodes Pass an array of curl error codes to override the default list of error codes.
     *
     * @return callable
     */
    public static function createCurlFilter($errorCodes = null)
    {
        $errorCodes = $errorCodes ?: [CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT, CURLE_PARTIAL_FILE, CURLE_WRITE_ERROR,
            CURLE_READ_ERROR, CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR, CURLE_HTTP_PORT_FAILED, CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR, CURLE_RECV_ERROR];

        $errorCodes = array_fill_keys($errorCodes, 1);

        return function ($retries, AbstractTransferStatsEvent $event) use ($errorCodes) {
            return isset($errorCodes[(int) $event->getTransferInfo('curl_result')]);
        };
    }

    /**
     * Creates a chain of callables that triggers one after the other until a callable returns true.
     *
     * @param array $filters Array of callables that accept the number of retries and an after send event and return
     *                       true to retry the transaction or false to not retry.
     *
     * @return callable Returns a filter that can be used to determine if a transaction should be retried
     */
    public static function createChainFilter(array $filters)
    {
        return function ($retries, AbstractTransferStatsEvent $event) use ($filters) {
            foreach ($filters as $filter) {
                if ($filter($retries, $event)) {
                    return true;
                }
            }

            return false;
        };
    }
}
