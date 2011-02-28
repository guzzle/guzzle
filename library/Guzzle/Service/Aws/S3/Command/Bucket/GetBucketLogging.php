<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Model\BucketLoggingStatus;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Get the logging settings of a bucket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 */
class GetBucketLogging extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'));
        $this->request->getQuery()->set('logging', false);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = new BucketLoggingStatus(new \SimpleXMLElement($this->getResponse()->getBody(true)));
    }

    /**
     * Returns an object containing information regarding the bucket logging
     * settings of the bucket
     *
     * @return BucketLoggingStatus
     */
    public function getResult()
    {
        return parent::getResult();
    }
}