<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;
use Guzzle\Http\EntityBody;

/**
 * This implementation of the PUT operation uses the versioning sub-resource to
 * set the versioning state of an existing bucket. To set the versioning state,
 * you must be the bucket owner.
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketPUTVersioningStatus.html
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketVersioning extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'));
        $this->request->getQuery()->set('versioning', false);

        // Add the MFA header if one is present
        if ($this->hasKey('mfa')) {
            $this->request->setHeader('x-amz-mfa', $this->get('mfa'));
        }

        // Construct the configuration body
        $config = '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';

        if ($this->hasKey('status')) {
            $config .= '<Status>' . ($this->get('status') ? 'Enabled' : 'Suspended') . '</Status>';
        }

        if ($this->hasKey('mfa_delete')) {
            $config .= '<MfaDelete>' . ($this->get('mfa_delete') ? 'Enabled' : 'Disabled') . '</MfaDelete>';
        }

        $config .= '</VersioningConfiguration>';
        
        $this->request->setBody(EntityBody::factory($config));
    }

    /**
     * Set the versioning state of the bucket.
     *
     * @param bool $enabled Set to TRUE to enable versioning or FALSE to disable
     *
     * @return PutBucketVersioning
     */
    public function setStatus($enabled)
    {
        return $this->set('status', (bool)$enabled);
    }

    /**
     * Specifies whether MFA Delete is enabled in the bucket versioning
     * configuration. When enabled, the bucket owner must include the
     * x-amz-mfa request header in requests to change the versioning state of
     * a bucket and to permanently delete a versioned object.
     *
     * @param bool $enabled Set to TRUE to enable MFA delete on this bucket
     *
     * @return PutBucketVersioning
     */
    public function setMfaDelete($enabled)
    {
        return $this->set('mfa_delete', (bool)$enabled);
    }

    /**
     * Set the MFA code to perform operations on this bucket
     *
     * The value is the concatenation of the authentication device's serial
     * number, a space, and the value displayed on your authentication device.
     *
     * @param string $code The MFA code
     *
     * @return PutBucketVersioning
     */
    public function setMfaHeader($mfa)
    {
        return $this->set('mfa', (string)$mfa);
    }
}