<?php

namespace GuzzleHttp\Event;

use GuzzleHttp\Adapter\TransactionInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Event that contains transaction statistics (time over the wire, lookup time,
 * etc.).
 *
 * Adapters that create this event SHOULD add, at a minimum, the 'total_time'
 * transfer statistic that measures the amount of time, in seconds, taken to
 * complete a transfer for the current request/response cycle. Each event
 * pertains to a single request/response transaction, NOT the entire
 * transaction (e.g. redirects).
 *
 * Adapters that add transaction statistics SHOULD follow the same string
 * attribute names that are provided by cURL listed at
 * http://php.net/manual/en/function.curl-getinfo.php. However, not all
 * adapters will have access to the advanced statistics provided by cURL. The
 * most useful transfer statistics are as follows:
 *
 * - total_time: Total transaction time in seconds for last transfer
 * - namelookup_time: Time in seconds until name resolving was complete
 * - connect_time: Time in seconds it took to establish the connection
 * - pretransfer_time: Time in seconds from start until just before file
 *   transfer begins.
 * - starttransfer_time: Time in seconds until the first byte is about to be
 *   transferred.
 * - speed_download: Average download speed, measured in bytes/second.
 * - speed_upload: Average upload speed, measured in bytes/second.
 */
abstract class AbstractTransferEvent extends AbstractRequestEvent
{
    private $transferInfo;

    /**
     * @param TransactionInterface $transaction  Transaction
     * @param array                $transferInfo Transfer statistics
     */
    public function __construct(
        TransactionInterface $transaction,
        $transferInfo = []
    ) {
        parent::__construct($transaction);
        $this->transferInfo = $transferInfo;
    }

    /**
     * Get all transfer information as an associative array if no $name
     * argument is supplied, or gets a specific transfer statistic if
     * a $name attribute is supplied (e.g., 'total_time').
     *
     * @param string $name Name of the transfer stat to retrieve
     *
     * @return mixed|null|array
     */
    public function getTransferInfo($name = null)
    {
        if (!$name) {
            return $this->transferInfo;
        }

        return isset($this->transferInfo[$name])
            ? $this->transferInfo[$name]
            : null;
    }

    /**
     * Get the response
     *
     * @return ResponseInterface|null
     */
    abstract public function getResponse();

    /**
     * Intercept the request and associate a response
     *
     * @param ResponseInterface $response Response to set
     */
    abstract public function intercept(ResponseInterface $response);
}
