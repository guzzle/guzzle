<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Exception thrown when a transfer times out before a response is received
 * and the timeout was not identified as connection establishment.
 */
class NetworkTimeoutException extends NetworkException implements TimeoutException
{
}
