<?php

namespace GuzzleHttp\Service\Event;

use GuzzleHttp\Event\AbstractEvent;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Service\CommandInterface;

class AbstractCommandEvent extends AbstractEvent
{
    /** @var CommandInterface */
    protected $command;

    /** @var RequestInterface */
    protected $request;

    /** @var mixed|null */
    protected $result;

    /**
     * Get the command associated with the event
     *
     * @return CommandInterface
     */
    public function getCommand()
    {
        return $this->command;
    }

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
