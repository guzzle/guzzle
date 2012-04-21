<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;

/**
 * HTTP request that sends an entity-body in the request message (POST, PUT)
 */
interface EntityEnclosingRequestInterface extends RequestInterface
{
    /**
     * Set the body of the request
     *
     * @param string|resource|EntityBody $body Body to use in the entity body
     *      of the request
     * @param string $contentType (optional) Content-Type to set.  Leave null
     *      to use an existing Content-Type or to guess the Content-Type
     * @param bool $tryChunkedTransfer (optional) Set to TRUE to try to use
     *      Tranfer-Encoding chunked
     *
     * @return EntityEnclosingRequestInterface
     * @throws RequestException if the protocol is < 1.1 and Content-Length can
     *      not be determined
     */
    function setBody($body, $contentType = null, $tryChunkedTransfer = false);

    /**
     * Get the body of the request if set
     *
     * @return EntityBody|null
     */
    function getBody();

    /**
     * Get a POST field from the request
     *
     * @param string $field Field to retrive
     *
     * @return mixed|null
     */
    function getPostField($field);

    /**
     * Get the post fields that will be used in the request
     *
     * @return array
     */
    function getPostFields();

    /**
     * Returns an associative array of POST field names and file paths
     *
     * @return array
     */
    function getPostFiles();

    /**
     * Add POST fields to use in the request
     *
     * @param QueryString|array $fields POST fields
     *
     * @return EntityEnclosingRequestInterface
     */
    function addPostFields($fields);

    /**
     * Set a POST field value
     *
     * @param string $key Key to set
     * @param string $value Value to set
     *
     * @return EntityEnclosingRequestInterface
     */
    function setPostField($key, $value);

    /**
     * Add POST files to use in the upload
     *
     * @param array $files An array of filenames to POST
     *
     * @return EntityEnclosingRequestInterface
     * @throws BodyException if the file cannot be read
     */
    function addPostFiles(array $files);

    /**
     * Remove a POST field or file by name
     *
     * @param string $field Name of the POST field or file to remove
     *
     * @return EntityEnclosingRequestInterface
     */
    function removePostField($field);
}
