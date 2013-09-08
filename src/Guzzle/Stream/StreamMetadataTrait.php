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
}
