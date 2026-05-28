<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Exception thrown when a handler is closed before a transfer completes.
 */
class HandlerClosedException extends TransferException
{
}
