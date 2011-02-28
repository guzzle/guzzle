<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Aborts a multipart upload to an object
 *
 * @guzzle bucket doc="Bucket that contains the object" required="true"
 * @guzzle key doc="Key of the object to abort the upload" required="true"
 * @guzzle upload_id doc="Multipart upload ID" required="true"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbortMultipartUpload extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::DELETE, $this->get('bucket'), $this->get('key'));
        $this->request->getQuery()->set('uploadId', $this->get('upload_id'));
    }

    /**
     * Set the key of the object
     *
     * @param string $key The key or name of the object
     *
     * @return AbortMultipartUpload
     */
    public function setKey($key)
    {
        return $this->set('key', $key);
    }

    /**
     * Set the multipart upload ID
     *
     * @param string $uploadId
     *
     * @return AbortMultipartUpload
     */
    public function setUploadId($uploadId)
    {
        return $this->set('upload_id', $uploadId);
    }
}