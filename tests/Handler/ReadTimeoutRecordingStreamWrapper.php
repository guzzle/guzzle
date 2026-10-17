<?php

namespace GuzzleHttp\Tests\Handler;

/**
 * A stream wrapper that records the read timeouts PHP applies to its streams.
 */
final class ReadTimeoutRecordingStreamWrapper
{
    /** @var array<int, array{int, int}> */
    public static $timeouts = [];

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        if ($option === \STREAM_OPTION_READ_TIMEOUT) {
            self::$timeouts[] = [$arg1, $arg2];
        }

        return true;
    }
}
