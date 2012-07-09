<?php

namespace Guzzle\Http\Message;

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
     * @var array POST files to send with the request
     */
    protected $postFiles = array();

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
        // Only attempt to include the POST data if it's only fields
        if (count($this->postFields) && empty($this->postFiles)) {
            return parent::__toString() . (string) $this->postFields;
        }

        return parent::__toString() . $this->body;
    }

    /**
     * {@inheritdoc}
     */
    public function setState($state)
    {
        parent::setState($state);
        if ($state == self::STATE_TRANSFER && !$this->body && !count($this->postFields) && !count($this->postFiles)) {
            $this->setHeader('Content-Length', 0)->removeHeader('Transfer-Encoding');
        }

        return $this;
    }

    /**
     * Set the body of the request
     *
     * @param string|resource|EntityBody $body               Body to use in the entity body of the request
     * @param string                     $contentType        Content-Type to set.  Leave null to use an existing
     *                                                       Content-Type or to guess the Content-Type
     * @param bool                       $tryChunkedTransfer Set to TRUE to try to use Transfer-Encoding chunked
     *
     * @return EntityEnclosingRequest
     * @throws RequestException if the protocol is < 1.1 and Content-Length can not be determined
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
            } elseif ('1.1' == $this->protocolVersion) {
                $this->setHeader('Transfer-Encoding', 'chunked');
            } else {
                throw new RequestException(
                    'Cannot determine Content-Length and cannot use chunked Transfer-Encoding when using HTTP/1.0'
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
     * @param string $field Field to retrieve
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
     * @return QueryString
     */
    public function getPostFields()
    {
        return $this->postFields;
    }

    /**
     * Set a POST field value
     *
     * @param string $key   Key to set
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
     * Returns an associative array of POST field names to an array of PostFileInterface objects
     *
     * @return array
     */
    public function getPostFiles()
    {
        return $this->postFiles;
    }

    /**
     * Get a POST file from the request
     *
     * @param string $fieldName POST fields to retrieve
     *
     * @return PostFileInterface|null Returns an array wrapping PostFileInterface objects
     */
    public function getPostFile($fieldName)
    {
        return isset($this->postFiles[$fieldName]) ? $this->postFiles[$fieldName] : null;
    }

    /**
     * Remove a POST file from the request
     *
     * @param string $fieldName POST file field name to remove
     *
     * @return EntityEnclosingRequest
     */
    public function removePostFile($fieldName)
    {
        unset($this->postFiles[$fieldName]);
        $this->processPostFields();

        return $this;
    }

    /**
     * Add a POST file to the upload
     *
     * @param string|PostFileUpload $field       POST field to use (e.g. file) or PostFileInterface object.
     * @param string                $filename    Full path to the file. Do not include the @ symbol.
     * @param string                $contentType Optional Content-Type to add to the Content-Disposition.
     *                                           Default behavior is to guess. Set to false to not specify.
     *
     * @return EntityEnclosingRequest
     * @throws RequestException if the file cannot be read
     */
    public function addPostFile($field, $filename = null, $contentType = null)
    {
        $data = null;

        if ($field instanceof PostFileInterface) {
            $data = $field;
        } elseif (!is_string($filename)) {
            throw new RequestException('The path to a file must be a string');
        } elseif (!empty($filename)) {
            // Adding an empty file will cause cURL to error out
            $data = new PostFile($field, $filename, $contentType);
        }

        if ($data) {
            if (!isset($this->postFiles[$data->getFieldName()])) {
                $this->postFiles[$data->getFieldName()] = array($data);
            } else {
                $this->postFiles[$data->getFieldName()][] = $data;
            }
            $this->processPostFields();
        }

        return $this;
    }

    /**
     * Add POST files to use in the upload
     *
     * @param array $files An array of POST fields => filenames where filename can be a string or PostFileInterfaces
     *
     * @return EntityEnclosingRequest
     * @throws RequestException if the file cannot be read
     */
    public function addPostFiles(array $files)
    {
        foreach ($files as $key => $file) {
            if ($file instanceof PostFileInterface) {
                $this->addPostFile($file, null, null, false);
            } elseif (is_string($file)) {
                // Convert non-associative array keys into 'file'
                if (is_numeric($key)) {
                    $key = 'file';
                }
                $this->addPostFile($key, $file, null, false);
            } else {
                throw new RequestException('File must be a string or instance of PostFileInterface');
            }
        }

        return $this;
    }

    /**
     * Determine what type of request should be sent based on post fields
     */
    protected function processPostFields()
    {
        if (empty($this->postFiles)) {
            $this->removeHeader('Expect')->setHeader('Content-Type', self::URL_ENCODED);
        } else {
            $this->setHeader('Expect', '100-Continue')->setHeader('Content-Type', self::MULTIPART);
        }
    }
}
