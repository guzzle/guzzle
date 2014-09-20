<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Message\ResponseInterface;

/**
 * Event that contains transaction statistics (time over the wire, lookup time,
 * etc.).
 */
abstract class AbstractTransferEvent extends AbstractRequestEvent
{
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
        return !$name
            ? $this->transaction->transferInfo
            : (isset($this->transaction->transferInfo[$name])
                ? $this->transaction->transferInfo[$name]
                : null);
    }

    /**
     * Get the response
     *
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->transaction->response;
    }
}
