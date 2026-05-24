<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

final class CurlShare
{
    public const NONE = 'none';
    public const HANDLER = 'handler';
    public const PERSISTENT_PREFER = 'persistent_prefer';
    public const PERSISTENT_REQUIRE = 'persistent_require';

    private function __construct()
    {
    }
}
