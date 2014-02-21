<?php

namespace GuzzleHttp\Service\Event;

use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\CommandInterface;

/**
 * Event emitted when the HTTP response of a command is being processed.
 *
 * Event listeners can inject a result onto the event to change the result of
 * the command.
 */
class ProcessEvent extends AbstractCommandEvent
{
    /** @var ResponseInterface */
    private $response;

    /**
     * @param CommandInterface  $command  Command
     * @param RequestInterface  $request  Request that was sent
     * @param ResponseInterface $response Response that was received
     */
    public function __construct(
        CommandInterface $command,
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->command = $command;
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Get the response that was received for the request.
     *
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Set the processed result on the event.
     *
     * @param mixed $result Result to associate with the command
     */
    public function setResult($result)
    {
        $this->result = $result;
    }
}
