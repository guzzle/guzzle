<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;
use Guzzle\Http\EntityBody;

/**
 * Set the policy of a bucket
 *
 * http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketPUTpolicy.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket doc="Bucket to set the policy on" required="true"
 * @guzzle policy doc="Bucket policy to set.  JSON" required="true"
 */
class PutBucketPolicy extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'));
        $this->request->getQuery()->set('policy', false);
        $this->request->setBody(EntityBody::factory($this->get('policy')));
    }

    /**
     * Set the bucket policy
     *
     * @param string|array $policy Bucket policy to set as a JSON string or
     *      an array that will be automatically converted to a JSON string
     *
     * @return PutBucketPolicy
     */
    public function setPolicy($policy)
    {
        return $this->set('policy', is_array($policy) ? json_encode($policy) : $policy);
    }
}