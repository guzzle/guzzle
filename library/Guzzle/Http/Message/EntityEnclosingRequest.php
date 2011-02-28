<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Common\Filter\Chain;
use Guzzle\Common\Filter\FilterInterface;
use Guzzle\Common\Subject\SubjectMediator;
use Guzzle\Common\Subject\Observer;
use Guzzle\Http\EntityBody;
use Guzzle\Http\QueryString;
use Guzzle\Http\MultipartFormData;

/**
 * HTTP request that sends an entity-body in the request message (POST, PUT)
 *
 * @author Michael Dowling <michael@guzzlephp.org>
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
     * Get the HTTP request as a string
     *
     * @return string
     */
    public function __toString()
    {
        // Process the object as it might contain POST fields that need to be
        // generated into an EntityBody
        if (!$this->response) {
            $this->process($this);
        }

        $body = count($this->getPostFields()) && 0 == count($this->getPostFiles())
            ? (string) $this->getPostFields()
            : (string) $this->getBody();

        return parent::__toString() . $body;
    }

    /**
     * Set the body of the request
     *
     * @param EntityBody $body Body to use in the entity body of the request
     *
     * @return EntityEnclosingRequest
     */
    public function setBody(EntityBody $body)
    {
        $this->body = $body;
        $this->addChain();

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
     * {@inheritdoc}
     */
    public function process($context)
    {
        // @codeCoverageIgnoreStart
        if ($context !== $this) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $continuePayload = false;
        
        if (count($this->getPostFields())) {

            // If there are files, then do a multipart/form-data entity body
            $this->getCurlOptions()->set(CURLOPT_POST, true);
            if (count($this->getPostFiles())) {
                $this->setHeader('Content-Type', 'multipart/form-data');
                $this->postFields->setEncodeFields(false)->setEncodeValues(false);
                $this->getCurlOptions()->set(CURLOPT_POSTFIELDS, $this->postFields->getAll());
                $continuePayload = true;
            } else {
                $this->setHeader('Content-Type', 'application/x-www-form-urlencoded');
                $this->getCurlOptions()->set(CURLOPT_POSTFIELDS, (string) $this->postFields);
            }

        } else if ($this->body
            && !$this->getHeader('Transfer-Encoding', null, true)
            && !$this->hasHeader('Content-Length', true)) {

            $continuePayload = true;

            // Set the Content-Length header if it can be determined
            $size = $this->body->getContentLength();

            if (!is_null($size) && !is_bool($size)) {
                $this->headers->set('Content-Length', $size);
            } else if ('1.1' == $this->protocolVersion) {
                $this->setHeader('Transfer-Encoding', 'chunked');
            } else {
                throw new RequestException('Cannot determine entity body '
                    . 'size and cannot use chunked Transfer-Encoding when '
                    . 'using HTTP/' . $this->protocolVersion
                );
            }
        }

        // Always add the Expect: 100-Continue header when sending an entity
        // body other than application/x-www-form-urlencoded using HTTP/1.1
        if ($continuePayload && $this->protocolVersion == '1.1' && $this->body
            && !$this->getHeader('Expect', null, true)) {
            $this->setHeader('Expect', '100-Continue');
        }
    }

    /**
     * Get the post fields that will be used in the request
     *
     * @return QueryString
     */
    public function getPostFields()
    {
        if (!$this->postFields) {
            $this->postFields = new QueryString();
            $this->postFields->setPrefix('');
            $this->addChain();
        }

        return $this->postFields;
    }

    /**
     * Returns an array of files that will be sent in the request.
     *
     * The '@' prefix is removed from the files in the return array
     *
     * @return array
     */
    public function getPostFiles()
    {
        $files = $this->getPostFields()->filter(function($key, $value) {
            return $value && $value[0] == '@';
        })->getAll();
            
        foreach ($files as $key => &$value) {
            $value = ($value[0] == '@') ? substr($value, 1) : $value;
        }

        return $files;
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
        if (!$this->hasHeader('Content-Type')) {
            $this->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        }
        $this->getPostFields()->merge($fields);

        return $this;
    }

    /**
     * Add POST files to use in the upload
     *
     * @param array $files An array of filenames to POST
     *
     * @return EntityEnclosingRequest
     *
     * @throws BodyException if the file cannot be read
     */
    public function addPostFiles(array $files)
    {
        // Post files have been added, so pass the parameters as an
        // array.  Passing as an array causes the Content-Type to be
        // multipart/form-data
        $this->setHeader('Content-Type', 'multipart/form-data');

        $files = (array) $files;
        $normalized = array();
        $total = count($files);

        foreach ($files as $key => $file) {

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
            $this->getPostFields()->add($key, $file);
        }

        return $this;
    }

    /**
     * Attach the POST request to the parent pre-processing chain
     */
    private function addChain()
    {
        $chain = $this->getPrepareChain();
        if (!$chain->hasFilter($this)) {
            $chain->prependFilter($this);
        }
    }
}