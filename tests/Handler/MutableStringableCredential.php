<?php

namespace GuzzleHttp\Tests\Handler;

/**
 * A credential whose string value is controlled externally, so a stateless
 * instance can serialize identically while stringifying differently.
 */
final class MutableStringableCredential
{
    /** @var string */
    public static $value = 'username:one';

    /** @var int */
    public static $calls = 0;

    public function __toString(): string
    {
        ++self::$calls;

        return self::$value;
    }
}
