<?php

namespace Guzzle\Plugin\Mock\Exception;

use OutOfBoundsException;

/**
 * Thrown when a request is not matched to a response.
 */
class UnmatchedRequestException extends OutOfBoundsException
{
}
