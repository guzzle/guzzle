<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * This implementation of the GET operation uses the versioning sub-resource to
 * return the versioning state of a bucket. To retrieve the versioning state of
 * a bucket, you must be the bucket owner.
 *
 * This implementation also returns the MFA Delete status of the versioning
 * state, i.e., if the MFA Delete status is enabled, the bucket owner must use
 * an  authentication device to change the versioning state of the bucket.
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketGETversioningStatus.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 */
class GetBucketVersioning extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'));
        $this->request->getQuery()->set('versioning', false);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = new \SimpleXMLElement($this->getResponse()->getBody(true));
    }
}