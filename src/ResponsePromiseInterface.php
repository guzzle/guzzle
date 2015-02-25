<?php
namespace GuzzleHttp;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

interface ResponsePromiseInterface extends ResponseInterface, PromiseInterface
{
}
