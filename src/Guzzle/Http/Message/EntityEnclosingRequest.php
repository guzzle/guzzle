<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\Exception\RequestException;

/**
 * HTTP request that sends an entity-body in the request message (POST, PUT)
 */
class EntityEnclosingRequest extends Request implements EntityEnclosingRequestInterface
{
    /**
     * @var EntityBody $body Body of the request
     */
    protected $body;

    /**
     * @var QueryString POST fields to use in the EntityBody
     */
    protected $postFields;

    /**
     * {@inheritdoc}
     */
    public function __construct($method, $url, $headers = array())
    {
        $this->postFields = new QueryString();
        $this->postFields->setPrefix('');
        parent::__construct($method, $url, $headers);
    }

    /**
     * Get the HTTP request as a string
     *
     * @return string
     */
    public function __toString()
    {
        return parent::__toString()
            . (count($this->getPostFields()) ? $this->postFields : $this->body);
    }

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
     * @return EntityEnclosingRequest
     * @throws RequestException if the protocol is < 1.1 and Content-Length can
     *      not be determined
     */
    public function setBody($body, $contentType = null, $tryChunkedTransfer = false)
    {
        $this->body = EntityBody::factory($body);
        $this->removeHeader('Content-Length');
        $this->setHeader('Expect', '100-Continue');

        if ($contentType) {
            $this->setHeader('Content-Type', (string) $contentType);
        }

        if ($tryChunkedTransfer) {
            $this->setHeader('Transfer-Encoding', 'chunked');
        } else {
            $this->removeHeader('Transfer-Encoding');
            // Set the Content-Length header if it can be determined
            $size = $this->body->getContentLength();
            if ($size !== null && $size !== false) {
                $this->setHeader('Content-Length', $size);
            } else if ('1.1' == $this->protocolVersion) {
                $this->setHeader('Transfer-Encoding', 'chunked');
            } else {
                throw new RequestException('Cannot determine entity body '
                    . 'size and cannot use chunked Transfer-Encoding when '
                    . 'using HTTP/' . $this->protocolVersion
                );
            }
        }

        return $this;
    }

    /**
     * Get the body of the request if set
     *
     * @return EntityBody|null
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Get a POST field from the request
     *
     * @param string $field Field to retrive
     *
     * @return mixed|null
     */
    public function getPostField($field)
    {
        return $this->postFields->get($field);
    }

    /**
     * Get the post fields that will be used in the request
     *
     * @return array
     */
    public function getPostFields()
    {
        return $this->postFields->getAll();
    }

    /**
     * Returns an associative array of POST field names and file paths
     *
     * @return array
     */
    public function getPostFiles()
    {
        return $this->postFields->filter(function($key, $value) {
            return $value && is_string($value) && $value[0] == '@';
        })->map(function($key, $value) {
            return str_replace('@', '', $value);
        })->getAll();
    }

    /**
     * Add POST fields to use in the request
     *
     * @param QueryString|array $fields POST fields
     *
     * @return EntityEnclosingRequest
     */
    public function addPostFields($fields)
    {
        $this->postFields->merge($fields);
        $this->processPostFields();

        return $this;
    }

    /**
     * Set a POST field value
     *
     * @param string $key Key to set
     * @param string $value Value to set
     *
     * @return EntityEnclosingRequest
     */
    public function setPostField($key, $value)
    {
        $this->postFields->set($key, $value);
        $this->processPostFields();

        return $this;
    }

    /**
     * Add POST files to use in the upload
     *
     * @param array $files An array of filenames to POST
     *
     * @return EntityEnclosingRequest
     * @throws BodyException if the file cannot be read
     */
    public function addPostFiles(array $files)
    {
        foreach ((array) $files as $key => $file) {

            if (is_numeric($key)) {
                $key = 'file';
            }

            $found = ($file[0] == '@')
                ? is_readable(substr($file, 1))
                : is_readable($file);
            if (!$found) {
                throw new RequestException('File cannot be opened for reading: ' . $file);
            }
            if ($file[0] != '@') {
                $file = '@' . $file;
            }
            $this->postFields->add($key, $file);
        }

        $this->processPostFields();

        return $this;
    }

    /**
     * Remove a POST field or file by name
     *
     * @param string $field Name of the POST field or file to remove
     *
     * @return EntityEnclosingRequest
     */
    public function removePostField($field)
    {
        $this->postFields->remove($field);
        $this->processPostFields();

        return $this;
    }

    /**
     * Determine what type of request should be sent based on post fields
     */
    protected function processPostFields()
    {
        if (0 == count($this->getPostFiles())) {
            $this->setHeader('Content-Type', 'application/x-www-form-urlencoded');
            $this->removeHeader('Expect');
            $this->getCurlOptions()->set(CURLOPT_POSTFIELDS, (string) $this->postFields);
        } else {
            $this->setHeader('Expect', '100-Continue');
            $this->setHeader('Content-Type', 'multipart/form-data');
            $this->postFields->setEncodeFields(false)->setEncodeValues(false);
            $this->getCurlOptions()->set(CURLOPT_POSTFIELDS, $this->postFields->getAll());
        }
    }
}
