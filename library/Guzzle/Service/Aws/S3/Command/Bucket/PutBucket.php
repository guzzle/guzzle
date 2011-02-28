<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\EntityBody;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Create a new bucket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true" doc="Name of the bucket to create"
 * @guzzle acl doc="Canned ACL to set on the bucket"
 * @guzzle region doc="Region where the bucket is to be created"
 */
class PutBucket extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'));

        if ($this->hasKey('acl')) {
            $this->request->setHeader('x-amz-acl', $this->get('acl'));
        }

        // The following logic is to make it easier to add more configuration
        // options in the future if AWS ever adds more options to configurations
        $bucketConfiguration = null;
        if ($this->hasKey('region')) {
            if (!$bucketConfiguration) {
                $bucketConfiguration = $this->createConfigurationWrapper();
            }
            $bucketConfiguration->addChild('LocationConstraint', $this->get('region'));
        }

        // If a bucket configuration has been created, then send it as the body
        if ($bucketConfiguration) {
            $this->request->setBody(EntityBody::factory(trim($bucketConfiguration->asXML())));
        }
    }

    /**
     * Creates the wrapper for a bucket configuration
     *
     * @return SimpleXMLElement
     */
    protected function createConfigurationWrapper()
    {
        return new \SimpleXMLElement('<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></CreateBucketConfiguration>');
    }

    /**
     * Sets the ACL of the bucket you're creating.
     *
     * @param string $acl Valid Values: private | public-read | public-read-write |
     *      authenticated-read | bucket-owner-read | bucket-owner-full-control
     *
     * @return PutBucket
     */
    public function setAcl($acl)
    {
        return $this->set('acl', (string)$acl);
    }

    /**
     * Set the Region where the bucket will be created
     *
     * @param string $region S3 region (e.g. us-west-1) Default is US Standard
     *
     * @return PutBucket
     */
    public function setRegion($region)
    {
        return $this->set('region', (string)$region);
    }
}