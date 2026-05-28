<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Exception thrown when a connection cannot be established within the time limit.
 */
class ConnectTimeoutException extends ConnectException implements TimeoutException
{
}
