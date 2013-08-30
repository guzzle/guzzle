<?php

namespace Guzzle\Stream;

/**
 * Stream metadata trait
 */
trait StreamMetadataTrait
{
    /** @var array Stream metadata */
    private $meta = array();

    public function getMetadata($key = null)
    {
        return !$key ? $this->meta : (isset($this->meta[$key]) ? $this->meta[$key] : null);
    }

    public function setMetadata($key, $value)
    {
        static $immutable = ['wrapper_type', 'stream_type', 'mode', 'unread_bytes',
            'seekable', 'uri', self::IS_LOCAL, self::IS_READABLE, self::IS_WRITABLE];

        if (in_array($key, $immutable)) {
            throw new \InvalidArgumentException("Cannot change immutable value of stream: {$key}");
        }

        $this->meta[$key] = $value;

        return $this;
    }
}
