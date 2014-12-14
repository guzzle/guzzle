<?php

namespace GuzzleHttp\Post;

interface PostElementInterface
{
    /**
     * Gets all POST headers of the element.
     *
     * The keys represent the header name as it will be sent over the wire, and
     * each value is a string.
     *
     * @return array Returns an associative array of the file's headers.
     */
    public function getHeaders();

    /**
     * Add an header to the post element
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function addHeader($name, $value);
}
