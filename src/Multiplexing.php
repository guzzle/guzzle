<?php

namespace GuzzleHttp;

/**
 * Multiplexing modes for the "multiplex" request option.
 */
final class Multiplexing
{
    public const ALLOW = 'allow';
    public const PREFER = 'prefer';
    public const REQUIRE = 'require';

    private function __construct()
    {
    }
}
