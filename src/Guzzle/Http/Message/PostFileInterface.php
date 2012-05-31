<?php

namespace Guzzle\Http\Message;

/**
 * POST file upload
 */
interface PostFileInterface
{
    /**
     * Set the name of the field
     *
     * @param string $name Field name
     *
     * @return self
     */
    function setFieldName($name);

    /**
     * Get the name of the field
     *
     * @return string
     */
    function getFieldName();

    /**
     * Set the path to the file
     *
     * @param string $path Full path to the file
     *
     * @return self
     * @throws InvalidArgumentException if the file cannot be read
     */
    function setFilename($path);

    /**
     * Get the full path to the file
     *
     * @return string
     */
    function getFilename();

    /**
     * Set the Content-Type of the file
     *
     * @param string $type Content type
     *
     * @return self
     */
    function setContentType($type);

    /**
     * Get the Content-Type of the file
     *
     * @return string
     */
    function getContentType();

    /**
     * Get a cURL ready string for the upload
     *
     * @return string
     */
    function getCurlString();
}
