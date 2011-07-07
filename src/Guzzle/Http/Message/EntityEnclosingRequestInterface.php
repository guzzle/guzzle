<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Event\Observer;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;

/**
 * HTTP request that sends an entity-body in the request message (POST, PUT)
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface EntityEnclosingRequestInterface extends RequestInterface, Observer
{
    /**
     * Set the body of the request
     *
     * @param string|resource|EntityBody $body Body to use in the entity body
     *      of the request
     * @param string $contentType (optional) Content-Type to set.  Leave null
     *      to use an existing Content-Type or to guess the Content-Type
     *
     * @return EntityEnclosingRequestInterface
     */
    function setBody($body, $contentType = null);

    /**
     * Get the body of the request if set
     *
     * @return EntityBody|null
     */
    function getBody();

    /**
     * Get the post fields that will be used in the request
     *
     * @return QueryString
     */
    function getPostFields();

    /**
     * Returns an array of files that will be sent in the request.
     *
     * The '@' prefix is removed from the files in the return array
     *
     * @return array
     */
    function getPostFiles();

    /**
     * Add the POST fields to use in the request
     *
     * @param QueryString|array $fields POST fields
     *
     * @return EntityEnclosingRequestInterface
     */
    function addPostFields($fields);

    /**
     * Add POST files to use in the upload
     *
     * @param array $files An array of filenames to POST
     *
     * @return EntityEnclosingRequestInterface
     *
     * @throws BodyException if the file cannot be read
     */
    function addPostFiles(array $files);
}