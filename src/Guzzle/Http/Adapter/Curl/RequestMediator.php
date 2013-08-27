<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Stream\Stream;

/**
 * Mediator between curl handles and request objects
 */
class RequestMediator
{
    /** @var RequestInterface */
    private $request;

    /** @var ResponseInterface */
    private $response;

    /**
     * @param RequestInterface  $request Request to mediate
     * @param ResponseInterface $response Response to populate
     */
    public function __construct(RequestInterface $request, ResponseInterface $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Receive a response header from curl
     *
     * @param resource $curl   Curl handle
     * @param string   $header Received header
     *
     * @return int
     */
    public function receiveResponseHeader($curl, $header)
    {
        static $normalize = array("\r", "\n");
        $length = strlen($header);
        $header = str_replace($normalize, '', $header);

        if (substr($header, 0, 5) == 'HTTP/') {
            $startLine = explode(' ', $header, 3);
            // Only download the body to a target body when a successful response is received
            if ($startLine[1][0] != '2') {
                $this->response->setBody(null);
            }
            $this->response->setStatus($startLine[1], isset($startLine[2]) ? $startLine[2] : '');
            $this->response->setProtocolVersion(substr($startLine[0], -3));
        } elseif ($pos = strpos($header, ':')) {
            $this->response->addHeader(substr($header, 0, $pos), substr($header, $pos + 1));
        } elseif ($header == '' && !$this->response->isInformational()) {
            $this->request->dispatch(RequestEvents::GOT_HEADERS, [
                'request' => $this->request,
                'response' => $this->response
            ]);
        }

        return $length;
    }

    /**
     * Write data to the response body of a request
     *
     * @param resource $curl
     * @param string   $write
     *
     * @return int
     */
    public function writeResponseBody($curl, $write)
    {
        return $this->response->getBody()->write($write);
    }

    /**
     * Read data from the request body and send it to curl
     *
     * @param resource $ch     Curl handle
     * @param resource $fd     File descriptor
     * @param int      $length Amount of data to read
     *
     * @return string
     */
    public function readRequestBody($ch, $fd, $length)
    {
        return (string) $this->request->getBody()->read($length);
    }
}
