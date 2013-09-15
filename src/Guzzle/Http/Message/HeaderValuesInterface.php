<?php

namespace Guzzle\Http\Message;

/**
 * Represents a collection of header values
 */
interface HeaderValuesInterface extends \Countable, \Traversable, \ArrayAccess
{
    /**
     * Convert the header to a string, concatenating multiple values using
     * a comma.
     *
     * @return string
     */
    public function __toString();

    /**
     * Parse a header containing ";" separated data into an array of
     * associative arrays representing the header key value pair data of the
     * header. When a parameter does not contain a value, but just contains a
     * key, this function will inject a key with a '' string value.
     *
     * @return array
     */
    public function parseParams();
}
