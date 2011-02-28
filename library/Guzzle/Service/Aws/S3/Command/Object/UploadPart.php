<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;

/**
 * This operation uploads a part in a multipart upload.
 *
 * @guzzle key doc="Object key" required="true"
 * @guzzle bucket doc="Bucket that contains the object" required="true"
 * @guzzle body doc="Body to send to S3" required="true"
 * @guzzle upload_id doc="Upload ID" required="true"
 * @guzzle part_number doc="Part number" required="true"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 * @link   http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?mpUploadUploadPart.html
 */
class UploadPart extends AbstractRequestObject
{
    /**
     * @var bool Whether or not to send a checksum with the PUT
     */
    protected $validateChecksum = true;

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'), $this->get('key'));
        $this->request->getQuery()->set('partNumber', $this->get('part_number'))
                                         ->set('uploadId', $this->get('upload_id'));
        $this->applyDefaults($this->request);

        $this->request->setBody($this->get('body'));

        // Add the checksum to the PUT
        if ($this->validateChecksum) {
            $this->request->setHeader('Content-MD5', $this->get('body')->getContentMd5());
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = $this->getResponse()->getEtag();
    }

    /**
     * Get the ETag response header
     *
     * @return string
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Disable checksum validation when sending the object.
     *
     * Calling this method will prevent a Content-MD5 header from being sent in
     * the request.
     *
     * @return UploadPart
     */
    public function disableChecksumValidation()
    {
        $this->validateChecksum = false;

        return $this;
    }

    /**
     * Set the body of the object
     *
     * @param string|EntityBody $body Body of the object to set
     *
     * @return UploadPart
     */
    public function setBody($body)
    {
        return $this->set('body', EntityBody::factory($body));
    }

    /**
     * Set the upload ID of the request
     *
     * @param string $uploadId Upload ID
     *
     * @return UploadPart
     */
    public function setUploadId($uploadId)
    {
        return $this->set('upload_id', $uploadId);
    }

    /**
     * Set the part number of the request
     *
     * @param string $partNumber Part number
     *
     * @return UploadPart
     */
    public function setPartNumber($partNumber)
    {
        return $this->set('part_number', $partNumber);
    }
}