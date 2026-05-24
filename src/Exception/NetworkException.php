<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Base exception for network-related transfer failures.
 */
abstract class NetworkException extends TransferException implements NetworkExceptionInterface
{
}
