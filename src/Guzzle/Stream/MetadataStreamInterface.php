<?php

namespace Guzzle\Stream;

interface MetadataStreamInterface
{
    /**
     * Get stream metadata
     *
     * @param string $key Specific metadata to retrieve
     *
     * @return array|mixed|null
     */
    public function getMetadata($key = null);
}
