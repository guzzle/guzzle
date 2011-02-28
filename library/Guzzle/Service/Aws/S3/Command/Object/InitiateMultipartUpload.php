<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Aws\S3\S3Client;

/**
 * This operation initiates a multipart upload and returns an upload ID. This
 * upload ID is used to associate all the parts in the specific multipart
 * upload. You specify this upload ID in each of your subsequent upload part
 * requests (see Upload Part). You also include this upload ID in the final
 * request to either complete or abort the multipart upload request.
 *
 * @guzzle bucket doc="Bucket where the object is stored" required="true"
 * @guzzle key doc="Object key" required="true"
 * @guzzle headers doc="Headers to set on the request" type="class:Guzzle\Common\Collection"
 * @guzzle acl doc="Canned ACL to set on the object"
 * @guzzle storage_class doc="Use STANDARD or REDUCED_REDUNDANCY storage"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class InitiateMultipartUpload extends AbstractRequestObjectPut
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::POST, $this->get('bucket'), $this->get('key'));
        $this->request->getQuery()->set('uploads', false);
        $this->applyDefaults($this->request);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = new \SimpleXMLElement($this->getResponse()->getBody(true));
    }

    /**
     * Returns the upload information as a SimpleXMLElement object
     *
     * The returned XML object will have three values of interest:
     * Bucket, Key, and UploadId.
     *
     * @return SimpleXMLElement
     */
    public function getResult()
    {
        return parent::getResult();
    }
}