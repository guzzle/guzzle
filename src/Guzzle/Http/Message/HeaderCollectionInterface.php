<?php

namespace Guzzle\Http\Message;

/**
 * Represents a case-insensitive map of header names to header values.
 *
 * HeaderCollectionInterface must implement \Traversable. When iterated, each
 * key returned must be the name of a header, and each value returned must be
 * be an array of header values for the current key (i.e., the return value
 * must be the same as accessing the HeaderCollectionInterface at the key
 * using the ArrayAccess::offsetGet() method of the HeaderCollectionInterface).
 */
interface HeaderCollectionInterface extends \Traversable, \ArrayAccess
{
    /**
     * Convert the header collection into a string containing header names and
     * values concatenated using "\r\n".
     *
     * For example, this method should return
     * "Content-Type: text/html
     * X-Foo: baz, bar"
     *
     * @return string
     */
    public function __toString();

    /**
     * Remove all headers from the collection.
     */
    public function clear();

    /**
     * Add a header to the collection.
     *
     * Adding a header does not overwrite any existing headers of the same
     * case-insensitive name. If a header of the same case-insensitive name
     * already exists, then this header value is appended.
     *
     * @param string $name  Header name
     * @param string $value Header value
     */
    public function add($name, $value);

    /**
     * Finds all headers that match the provided name and returns a string
     * containing each matching header value concatenated used with a comma
     * followed by a space: ", ". If no matching headers are found, then this
     * method must return null.
     *
     * @param string $name Name of the header to retrieve
     *
     * @return null|string
     */
    public function getHeaderString($name);

    /**
     * Parse a parameterized header into an array key-value pairs.
     *
     * The parsed return value is an array of associative arrays. For every
     * distinct header value, there is an associative array containing key
     * value pair data.
     *
     * For example, given the following header:
     *
     *     Foo: Baz, Bar; test=abc; other=def
     *
     * The header will be parsed into the following array structure:
     *
     *     array(
     *         array('Baz'),
     *         array(
     *             0 => 'Bar',
     *             'test' => 'abc',
     *             'other' => 'def'
     *         )
     *     );
     *
     * @param string $headerName Name of the header to parse into parameters
     *
     * @return array
     */
    public function parseParams($headerName);

    /**
     * Checks if a header exists by the given case-insensitive name
     *
     * @param string $offset Header name
     *
     * @return boolean true on success or false on failure.
     */
    public function offsetExists($offset);

    /**
     * Retrieves an array of header values for a particular case-insensitive
     * header name.
     *
     * @param string $offset Header to retrieve
     *
     * @return array|null Returns an array of the matching header values if
     *                    found, or null if no matching headers are found.
     */
    public function offsetGet($offset);

    /**
     * Set a header value and overwrite any existing header that has the same
     * case-insensitive name.
     *
     * @param string $offset Header to set
     * @param array  $value  Array of header values strings to set.
     */
    public function offsetSet($offset, $value);

    /**
     * Remove a header by its case-insensitive name.
     *
     * @param string $offset Header name to remove
     */
    public function offsetUnset($offset);
}
