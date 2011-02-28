<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Delete an object from a bucket
 *
 * @guzzle key doc="Key of the object to delete" required="true"
 * @guzzle bucket doc="Bucket that contains the object" required="true"
 * @guzzle mfa doc="MFA token for deletion"
 * @guzzle version_id doc="Version ID of the object to delete"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DeleteObject extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::DELETE, $this->get('bucket'), $this->get('key'));
        
        if ($this->get('version_id')) {
            $this->request->getQuery()->set('versionId', $this->get('version_id'));
        }

        if ($this->get('mfa')) {
            $this->request->setHeader('x-amz-mfa', $this->get('mfa'));
        }
    }

    /**
     * Set the key of the object
     *
     * @param string $key The key or name of the object
     *
     * @return DeleteObject
     */
    public function setKey($key)
    {
        return $this->set('key', $key);
    }

    /**
     * Set a MFA token to authenticate deleting the object.  Only set this
     * parameter if MFA delete is enabled on the bucket.
     *
     * The passed $mfa value is the concatenation of your authentication
     * device's serial number, a space, and the authentication code displayed
     * on it.
     *
     * @param string $mfa
     * 
     * @return DeleteObject
     */
    public function setMfa($mfa)
    {
        return $this->set('mfa', $mfa);
    }

    /**
     * Set the version ID of the object to delete.  Only set this parameter
     * is bucket versioning is enabled on the bucket
     *
     * @param string $versionId
     * 
     * @return DeleteObject
     */
    public function setVersionId($versionId)
    {
        return $this->set('version_id', $versionId);
    }
}