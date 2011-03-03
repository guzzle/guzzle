<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Message;

use Guzzle\Common\Filter\FilterInterface;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;

/**
 * HTTP request that sends an entity-body in the request message (POST, PUT)
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
interface EntityEnclosingRequestInterface extends RequestInterface, FilterInterface
{
    /**
     * Set the body of the request
     *
     * @param string|resource|EntityBody $body Body to use in the entity body
     *      of the request
     *
     * @return EntityEnclosingRequestInterface
     */
    public function setBody($body);

    /**
     * Get the body of the request if set
     *
     * @return EntityBody|null
     */
    public function getBody();

    /**
     * Get the post fields that will be used in the request
     *
     * @return QueryString
     */
    public function getPostFields();

    /**
     * Returns an array of files that will be sent in the request.
     *
     * The '@' prefix is removed from the files in the return array
     *
     * @return array
     */
    public function getPostFiles();

    /**
     * Add the POST fields to use in the request
     *
     * @param QueryString|array $fields POST fields
     *
     * @return EntityEnclosingRequestInterface
     */
    public function addPostFields($fields);

    /**
     * Add POST files to use in the upload
     *
     * @param array $files An array of filenames to POST
     *
     * @return EntityEnclosingRequestInterface
     *
     * @throws BodyException if the file cannot be read
     */
    public function addPostFiles(array $files);
}