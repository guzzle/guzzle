<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Delete a bucket policy
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 * @link   http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketDELETEpolicy.html
 *
 * @guzzle bucket required="true" doc="Name of the bucket"
 */
class DeleteBucketPolicy extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::DELETE, $this->get('bucket'));
        $this->request->getQuery()->set('policy', false);
    }
}