<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Curl\CurlMultiInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to automatically retry failed HTTP requests using truncated
 * exponential backoff.
 */
class ExponentialBackoffPlugin extends AbstractHasDispatcher implements EventSubscriberInterface
{
    const DELAY_PARAM = 'plugins.exponential_backoff.retry_time';
    const RETRY_PARAM = 'plugins.exponential_backoff.retry_count';
    const RETRY_EVENT = 'plugins.exponential_backoff.retry';

    /**
     * @var array|callable Hash table of failure codes or PHP callable
     */
    protected $failureCodes;

    /**
     * @var bool Whether or not the failure codes property is a callable
     */
    protected $callableFailureCodes;

    /**
     * @var int Maximum number of times to retry a request
     */
    protected $maxRetries;

    /**
     * @var \Closure
     */
    protected $delayClosure;

    /**
     * @var array Array of default failure codes
     */
    protected static $defaultFailureCodes = array(500, 503,
        CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_WRITE_ERROR,
        CURLE_READ_ERROR, CURLE_OPERATION_TIMEOUTED, CURLE_SSL_CONNECT_ERROR,
        CURLE_HTTP_PORT_FAILED, CURLE_GOT_NOTHING, CURLE_SEND_ERROR,
        CURLE_RECV_ERROR);

    /**
     * @var array Hash cache of the default failure codes
     */
    protected static $defaultFailureCodesHash;

    /**
     * Construct a new exponential backoff plugin
     *
     * $failureCodes can be a list of numeric codes that match the response
     * code, a list of reason phrases that can match the reason phrase of a
     * request, or a list of cURL error code integers.  By default, this
     * plugin retries 500 and 503 responses as well as various CURL connection
     * errors.  You can pass in a callable that will be used to determine if a
     * response failed and must be retried.
     *
     * A custom callback function for $delayFunction must accept an integer
     * containing the current number of retries and return an integer
     * representing representing how many seconds to delay
     *
     * @param int            $maxRetries    The maximum number of time to retry a request
     * @param array|callable $failureCodes  List of failure codes or function.
     * @param callable       $delayFunction Method used to calculate the delay between requests.
     */
    public function __construct($maxRetries = 3, $failureCodes = null, $delayFunction = null)
    {
        $this->setMaxRetries($maxRetries);
        $this->setFailureCodes($failureCodes);

        if (!$delayFunction) {
            $this->delayClosure = array($this, 'calculateWait');
        } else {
            $this->delayClosure = $delayFunction;
        }

        // @codeCoverageIgnoreStart
        if (!self::$defaultFailureCodesHash) {
            self::$defaultFailureCodesHash = array_fill_keys(self::$defaultFailureCodes, 1);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get a default array of codes and cURL errors to retry
     *
     * @return array
     */
    public static function getDefaultFailureCodes()
    {
        return self::$defaultFailureCodes;
    }

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array(self::RETRY_EVENT);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.sent'      => 'onRequestSent',
            'request.exception' => 'onRequestSent',
            CurlMultiInterface::POLLING_REQUEST => 'onRequestPoll'
        );
    }

    /**
     * Set the maximum number of retries the plugin should use before failing
     * the request
     *
     * @param integer $maxRetries The maximum number of retries.
     *
     * @return ExponentialBackoffPlugin
     */
    public function setMaxRetries($maxRetries)
    {
        $this->maxRetries = max(0, (int) $maxRetries);

        return  $this;
    }

    /**
     * Get the maximum number of retries the plugin will attempt
     *
     * @return integer
     */
    public function getMaxRetries()
    {
        return $this->maxRetries;
    }

    /**
     * Get the HTTP response codes that should be retried using truncated
     * exponential backoff
     *
     * @return array
     */
    public function getFailureCodes()
    {
        return array_keys($this->failureCodes);
    }

    /**
     * Set the HTTP response codes that should be retried using truncated
     * exponential backoff
     *
     * @param mixed $codes Array of HTTP response codes or PHP callable
     *
     * @return ExponentialBackoffPlugin
     */
    public function setFailureCodes($codes = null)
    {
        // Use the default failure codes if no value was provided
        $this->failureCodes = $codes ?: static::getDefaultFailureCodes();
        // Determine if the passed failure codes are a PHP callable
        $this->callableFailureCodes = is_callable($this->failureCodes);

        // Use a hash of codes so that it is faster to lookup errors
        if (!$this->callableFailureCodes) {
            $this->failureCodes = array_fill_keys($this->failureCodes, 1);
        }

        return $this;
    }

    /**
     * Determine how long to wait using truncated exponential backoff
     *
     * @param int $retries Number of retries so far
     *
     * @return int
     */
    public function calculateWait($retries)
    {
        return (int) pow(2, $retries);
    }

    /**
     * Called when a request has been sent  and isn't finished processing
     *
     * @param Event $event
     */
    public function onRequestSent(Event $event)
    {
        $request = $event['request'];
        $response = $event['response'];
        $exception = $event['exception'];
        $handle = null;
        $retry = null;
        $failureCodes = $this->failureCodes;

        if ($this->callableFailureCodes) {
            // Use a callback to determine if the request should be retried
            $retry = call_user_func($this->failureCodes, $request, $response, $exception);
            // If null is returned, then use the default check
            if ($retry === null) {
                $failureCodes = self::$defaultFailureCodesHash;
            }
        }

        // If a retry method hasn't decided what to do yet, then use the default check
        if ($retry === null) {
            if ($exception && $exception instanceof CurlException) {
                // Handle cURL exceptions
                $retry = isset($failureCodes[$exception->getErrorNo()]);
                $handle = $exception->getCurlHandle();
            } elseif ($response) {
                $retry = isset($failureCodes[$response->getStatusCode()]) ||
                    isset($failureCodes[$response->getReasonPhrase()]);
            }
        }

        if ($retry) {
            $this->retryRequest($request, $response, $handle);
        }
    }

    /**
     * Called when a request is polling in the curl multi object
     *
     * @param Event $event
     */
    public function onRequestPoll(Event $event)
    {
        $request = $event['request'];
        $delay = $request->getParams()->get(self::DELAY_PARAM);

        // If the duration of the delay has passed, retry the request using the pool
        if (null !== $delay && microtime(true) >= $delay) {
            // Remove the request from the pool and then add it back again.
            // This is required for cURL to know that we want to retry sending
            // the easy handle.
            $request->getParams()->remove(self::DELAY_PARAM);
            // Rewind the request body if possible
            if ($request instanceof EntityEnclosingRequestInterface && $request->getBody()) {
                $request->getBody()->seek(0);
            }
            $multi = $event['curl_multi'];
            $multi->remove($request);
            $multi->add($request, true);
        }
    }

    /**
     * Trigger a request to retry
     *
     * @param RequestInterface $request  Request to retry
     * @param Response         $response Response received
     * @param CurlHandle       $handle   Curl handle
     */
    protected function retryRequest(RequestInterface $request, Response $response = null, CurlHandle $handle = null)
    {
        $params = $request->getParams();
        $retries = ((int) $params->get(self::RETRY_PARAM)) + 1;
        $params->set(self::RETRY_PARAM, $retries);

        // If this request has been retried too many times, then throw an exception
        if ($retries <= $this->maxRetries) {
            // Calculate how long to wait until the request should be retried
            $delay = call_user_func($this->delayClosure, $retries, $request);
            $delayTime = microtime(true) + $delay;
            // Send the request again
            $request->setState(RequestInterface::STATE_TRANSFER);
            $params->set(self::DELAY_PARAM, $delayTime);

            $this->dispatch(self::RETRY_EVENT, array(
                'request'  => $request,
                'response' => $response,
                'handle'   => $handle,
                'retries'  => $retries,
                'delay'    => $delay
            ));
        }
    }
}
