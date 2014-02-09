<?php

namespace Guzzle\Plugin\Retry;

use Guzzle\Common\EventSubscriberInterface;
use Guzzle\Http\Event\AbstractTransferStatsEvent;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Plugin\Log\MessageFormatter;
use Guzzle\Plugin\Log\SimpleLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Plugin to automatically retry failed HTTP requests using filters a delay strategy.
 */
class RetryPlugin implements EventSubscriberInterface
{
    const RETRY_FORMAT = '[{ts}] {method} {url} - {code} {phrase} - Retries: {retries}, Delay: {delay}, Time: {connect_time}, {total_time}, Error: {error}';

    /** @var callable */
    private $filter;

    /** @var callable */
    private $delayFunc;

    /** @var int */
    private $maxRetries;

    public static function getSubscribedEvents()
    {
        return [
            RequestEvents::AFTER_SEND => ['onRequestSent'],
            RequestEvents::ERROR      => ['onRequestSent']
        ];
    }

    /**
     * @param callable $filter    Filter used to determine whether or not to retry a request. The filter must be a
     *                            callable that accepts the current number of retries and an AbstractTransferStatsEvent
     *                            object. The filter must return true or false to denote if the request must be retried.
     * @param callable $delayFunc Callable that accepts the number of retries and an AbstractTransferStatsEvent and
     *                            returns the amount of of time in seconds to delay.
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
     * @param int                     $maxRetries Maximum number of retries
     * @param array                   $httpCodes  HTTP response codes to retry
     * @param array                   $curlCodes  cURL error codes to retry
     * @param LoggerInterface|bool    $logger     Pass a logger instance to log each retry or pass true for STDOUT
     * @param string|MessageFormatter $formatter  Pass a message formatter if logging to customize log messages
     *
     * @return self
     * @throws \InvalidArgumentException if logger is not a boolean or LoggerInterface
     */
    public static function getExponentialBackoff(
        $maxRetries = 3,
        array $httpCodes = null,
        array $curlCodes = null,
        $logger = null,
        $formatter = null
    ) {
        if (!extension_loaded('curl')) {
            $filter = self::createStatusFilter($httpCodes);
        } else {
            $filter = self::createChainFilter([
                self::createStatusFilter($httpCodes),
                self::createCurlFilter($curlCodes)
            ]);
        }

        $delay = [__CLASS__, 'exponentialDelay'];

        if ($logger) {
            if ($logger === true) {
                $logger = new SimpleLogger(STDOUT);
            } elseif (!($logger instanceof LoggerInterface)) {
                throw new \InvalidArgumentException('$logger must be true, false, or a LoggerInterface');
            }
            $delay = self::createLoggingDelay($delay, $logger, $formatter);
        }

        return new self($filter, $delay, $maxRetries);
    }

    public function onRequestSent(AbstractTransferStatsEvent $event)
    {
        $request = $event->getRequest();
        $retries = (int) $request->getConfig()->get('retries');

        if ($retries < $this->maxRetries) {
            $filterFn = $this->filter;
            if ($filterFn($retries, $event)) {
                $delayFn = $this->delayFunc;
                sleep($delayFn($retries, $event));
                $request->getConfig()->set('retries', ++$retries);
                $event->intercept($event->getClient()->send($request));
            }
        }
    }

    /**
     * Returns an exponential delay calculation
     *
     * @param int                        $retries Number of retries so far
     * @param AbstractTransferStatsEvent $event   Event containing transactional information
     *
     * @return int
     */
    public static function exponentialDelay($retries, AbstractTransferStatsEvent $event)
    {
        return (int) pow(2, $retries - 1);
    }

    /**
     * Creates a delay function that logs each retry before proxying to a wrapped delay function.
     *
     * @param callable                $delayFn   Delay function to proxy to
     * @param LoggerInterface         $logger    Logger used to log messages
     * @param string|MessageFormatter $formatter Format string or Message formatter used to format messages
     *
     * @return callable
     */
    public static function createLoggingDelay(
        callable $delayFn,
        LoggerInterface $logger,
        $formatter = null
    ) {
        if (!$formatter) {
            $formatter = new MessageFormatter(self::RETRY_FORMAT);
        } elseif (!($formatter instanceof MessageFormatter)) {
            $formatter = new MessageFormatter($formatter);
        }

        return function ($retries, AbstractTransferStatsEvent $event) use ($delayFn, $logger, $formatter) {
            $delay = $delayFn($retries, $event);
            $logger->log(LogLevel::NOTICE, $formatter->format(
                $event->getRequest(),
                $event->getResponse(),
                $event instanceof RequestErrorEvent ? $event->getException() : null,
                ['retries' => $retries + 1, 'delay' => $delay] + $event->getTransferInfo()
            ));
            return $delay;
        };
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
