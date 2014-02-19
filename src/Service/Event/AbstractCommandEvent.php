<?php

namespace GuzzleHttp\Service\Event;

use GuzzleHttp\Event\AbstractEvent;
use GuzzleHttp\Message\RequestInterface;

class AbstractCommandEvent extends AbstractEvent
{
    /** @var RequestInterface */
    protected $request;

    /** @var mixed|null */
    protected $result;

    /**
     * Gets the HTTP request that will be sent for the command (if one is set).
     *
     * @return RequestInterface|null
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Returns the result of the command if it was intercepted.
     *
     * @return mixed|null
     */
    public function getResult()
    {
        return $this->result;
    }
}
