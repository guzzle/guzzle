<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Check if a bucket exists
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class HeadBucket extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::HEAD, $this->get('bucket'));
        $this->request->getQuery()->set('max-keys', '0');
    }
}