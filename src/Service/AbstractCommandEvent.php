<?php

namespace GuzzleHttp\Service;

use GuzzleHttp\Event\AbstractEvent;
use GuzzleHttp\Message\RequestInterface;

class AbstractCommandEvent extends AbstractEvent
{
    /** @var CommandInterface */
    protected $command;

    /** @var RequestInterface */
    protected $request;

    /** @var mixed|null */
    protected $result;

    /** @var ServiceClientInterface */
    protected $client;

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

    /**
     * Get the client associated with the command transfer.
     *
     * @return ServiceClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }
}
