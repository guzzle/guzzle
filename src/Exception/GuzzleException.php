<?php
namespace GuzzleHttp\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

interface GuzzleException extends Throwable, ClientExceptionInterface
{
}
