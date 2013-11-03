<?php

namespace Guzzle\Plugin\Backoff;

use Guzzle\Http\Event\AbstractTransferStatsEvent;

abstract class AbstractRetryFilter implements RetryFilterInterface
{
    /** @var RetryFilterInterface */
    protected $next;

    /** @var array Default cURL errors to retry */
    protected static $defaultErrorCodes = array();

    /** @var array Error codes that can be retried */
    protected $errorCodes;

    /**
     * @param RetryFilterInterface $next  The next filter in the chain
     * @param array                $codes Error codes to retry
     */
    public function __construct(RetryFilterInterface $next = null, $codes = null)
    {
        $this->next = $next;
        $this->errorCodes = array_fill_keys($codes ?: static::$defaultErrorCodes, 1);
    }

    /**
     * Get the default failure codes to retry
     *
     * @return array
     */
    public static function getDefaultFailureCodes()
    {
        return static::$defaultErrorCodes;
    }

    public function shouldRetry($retries, AbstractTransferStatsEvent $event)
    {
        return $this->should($retries, $event)
            ?: $this->next && $this->next->shouldRetry($retries, $event);
    }

    abstract protected function should($retries, AbstractTransferStatsEvent $event);
}
