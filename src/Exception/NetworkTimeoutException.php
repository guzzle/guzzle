<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Exception thrown when a transfer times out after connection is established
 * but before a response is received.
 */
class NetworkTimeoutException extends NetworkException implements TimeoutException
{
}
