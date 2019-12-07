<?php

namespace GuzzleHttp\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

class InvalidRequestException extends \InvalidArgumentException implements RequestExceptionInterface, GuzzleException
{
    private $request;
    public function __construct(RequestInterface $request, $message)
    {
        $this->request = $request;
        parent::__construct($message);
    }
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
