<?php

namespace GuzzleHttp;

/**
 * Multiplexing modes for the "multiplex" request option.
 */
final class Multiplexing
{
    public const EAGER = 'eager';
    public const WAIT = 'wait';
    public const REQUIRE_EAGER = 'require_eager';
    public const REQUIRE_WAIT = 'require_wait';

    private function __construct()
    {
    }
}
