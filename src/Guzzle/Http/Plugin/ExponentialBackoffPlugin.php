<?php

namespace Guzzle\Http\Plugin;

use \Closure;
use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\Subject;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Pool\PoolInterface;

/**
 * Plugin to automatically retry failed HTTP requests using truncated
 * exponential backoff.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ExponentialBackoffPlugin implements Observer
{
    const DELAY_PARAM = 'plugins.exponential_backoff.retry_time';

    /**
     * @var array Array of response codes that must be retried
     */
    protected $failureCodes;

    /**
     * @var int Maximum number of times to retry a request
     */
    protected $maxRetries;

    /**
     * @var array Request state information
     */
    protected $state = array();

    /**
     * @var Closure
     */
    protected $delayClosure;

    /**
     * Construct a new exponential backoff plugin
     *
     * @param int $maxRetries (optional) The maximum number of time to retry a request
     * @param array $failureCodes (optional) Pass a custom list of failure codes.
     * @param Closure|array $delayClosure (optional) Method used to calculate the
     *      delay between requests.  The method must accept an integer containing
     *      the current number of retries and return an integer representing how
     *      many seconds to delay
     */
    public function __construct($maxRetries = 3, array $failureCodes = null, $delayClosure = null)
    {
        $this->setMaxRetries($maxRetries);
        $this->failureCodes = $failureCodes ?: array(500, 503);
        $this->delayClosure = $delayClosure ?: array($this, 'calculateWait');
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
        return $this->failureCodes;
    }

    /**
     * Set the HTTP response codes that should be retried using truncated
     * exponential backoff
     *
     * @param array $codes Array of HTTP response codes
     *
     * @return ExponentialBackoffPlugin
     */
    public function setFailureCodes(array $codes)
    {
        $this->failureCodes = $codes;

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
     * {@inheritdoc}
     */
    public function update(Subject $subject, $event, $context = null)
    {
        // @codeCoverageIgnoreStart
        if (!($subject instanceof RequestInterface)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        switch ($event) {
            case PoolInterface::POLLING_REQUEST:
                // The most frequent event, thus at the top of the switch
                $delay = $subject->getParams()->get(self::DELAY_PARAM);
                if ($delay) {
                    // If the duration of the delay has passed, retry the request using the pool
                    if (time() >= $delay) {
                        // Remove the request from the pool and then add it back again
                        $context->remove($subject);
                        $context->add($subject);
                        $subject->getParams()->remove(self::DELAY_PARAM);

                        return true;
                    }
                }
                break;
            case 'event.attach':
                // Called when the observer is initially attached to the request
                $this->state[spl_object_hash($subject)] = 0;
                break;
            case 'request.sent':
                // Called when the request has been sent and isn't finished processing
                $key = spl_object_hash($subject);

                if (in_array($subject->getResponse()->getStatusCode(), $this->failureCodes)) {
                    // If this request has been retried too many times, then throw an exception
                    if (++$this->state[$key] <= $this->maxRetries) {
                        // Calculate how long to wait until the request should be retried
                        $delay = (int) call_user_func($this->delayClosure, $this->state[$key]);
                        // Send the request again
                        $subject->setState(RequestInterface::STATE_NEW);

                        if ($subject->getParams()->get('pool')) {
                            // Pooled requests need to be sent via curl
                            // multi, and the retry will happen after a
                            // period of polling to prevent pool exclusivity
                            $subject->getParams()->set(self::DELAY_PARAM, time() + $delay);
                        } else {
                            // Wait for a delay then retry the request
                            sleep($delay);
                            $subject->send();
                        }
                    }
                }
                break;
        }
    }
}