<?php

namespace Guzzle\Http\Message;

/**
 * Represents a response yet to be completed
 */
class FutureResponse
{
    /** @var Request The request this response corresponds to */
    protected $request;

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * Waits for the response to be read completely
     *
     * @return Response
     */
    public function receive()
    {
        // wait here
        return $this->request->getResponse();
    }
}
