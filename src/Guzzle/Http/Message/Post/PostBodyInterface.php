<?php

namespace Guzzle\Http\Message\Post;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\ReadableStreamInterface;

/**
 * Represents a POST body that is sent as either a multipart/form-data stream or application/x-www-urlencoded stream
 */
interface PostBodyInterface extends ReadableStreamInterface, \Countable
{
    /**
     * Apply headers to the request appropriate for the current state of the object
     *
     * @param RequestInterface $request Request
     */
    public function applyRequestHeaders(RequestInterface $request);

    /**
     * Set a specific field
     *
     * @param string       $name  Name of the field to set
     * @param string|array $value Value to set
     *
     * @return $this
     */
    public function setField($name, $value);

    /**
     * Replace all existing form fields with an array of fields
     *
     * @param array $fields Associative array of fields to set
     *
     * @return $this
     */
    public function replaceFields(array $fields);

    /**
     * Get a specific field by name
     *
     * @param string $name Name of the POST field to retrieve
     *
     * @return string|null
     */
    public function getField($name);

    /**
     * Remove a field by name
     *
     * @param string $name Name of the field to remove
     *
     * @return $this
     */
    public function removeField($name);

    /**
     * Returns an associative array of names to values
     *
     * @return array
     */
    public function getFields();

    /**
     * Returns true if a field is set
     *
     * @param string $name Name of the field to set
     *
     * @return bool
     */
    public function hasField($name);

    /**
     * Get all of the files
     *
     * @return array Returns an array of PostFileInterface objects
     */
    public function getFiles();

    /**
     * Add a file to the POST
     *
     * @param PostFileInterface $file File to add
     *
     * @return $this
     */
    public function addFile(PostFileInterface $file);

    /**
     * Remove all files from the collection
     *
     * @return $this
     */
    public function clearFiles();
}
