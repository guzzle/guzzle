<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Exception thrown when an invalid argument is supplied to Guzzle.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements GuzzleException
{
}
