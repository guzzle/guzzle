<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Ring\Future\FutureInterface;

/**
 * Event that contains transfer statistics, and can be intercepted.
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
     * Returns true/false if a response is available.
     *
     * @return bool
     */
    public function hasResponse()
    {
        return !($this->transaction->response instanceof FutureInterface);
    }

    /**
     * Get the response.
     *
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->hasResponse() ? $this->transaction->response : null;
    }

    /**
     * Intercept the request and associate a response
     *
     * @param ResponseInterface $response Response to set
     */
    public function intercept(ResponseInterface $response)
    {
        $this->transaction->response = $response;
        $this->transaction->exception = null;
        $this->stopPropagation();
    }
}
