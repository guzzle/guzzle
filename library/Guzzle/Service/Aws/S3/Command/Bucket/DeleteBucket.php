<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Delete a bucket
 *
 * The contents of a bucket must be removed before issuing a DELETE command.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true" doc="Name of the bucket to delete"
 */
class DeleteBucket extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::DELETE, $this->get('bucket'));
    }
}