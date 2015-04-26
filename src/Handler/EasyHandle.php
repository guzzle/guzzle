<?php
namespace GuzzleHttp\Handler;

/**
 * Represents a cURL easy handle and the data it populates.
 *
 * @internal The API of this class may change.
 */
final class EasyHandle
{
    public $handle;
    public $body;
    public $headers = [];
}
