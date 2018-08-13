<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

use Psr\Http\Message\RequestInterface;

class InvalidRequestException extends \InvalidArgumentException implements \Psr\Http\Client\Exception\RequestException,  GuzzleException
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
