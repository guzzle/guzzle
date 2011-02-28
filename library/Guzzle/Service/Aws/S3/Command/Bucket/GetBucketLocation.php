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
 * Get the location constraint of a bucket.
 *
 * This implementation of the GET operation uses the location  sub-resource to
 * return a bucket's Region. You set the bucket's Region using the
 * LocationContraint  request parameter in a PUT  Bucket request.
 *
 * To use this implementation of the operation, you must be the bucket owner
 *
 * The result of this command will be a string containing the location of the
 * bucket.  Buckets with no location constraint will be represented as 'US'
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 */
class GetBucketLocation extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'));
        $this->request->getQuery()->set('location', false);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $xml = new \SimpleXMLElement($this->getResponse()->getBody(true));
        $this->result = (string)$xml ?: S3Client::BUCKET_LOCATION_US;            
    }

    /**
     * Returns the location constraint of the bucket or FALSE if an error
     * occurred.
     *
     * @return string
     */
    public function getResult()
    {
        return parent::getResult();
    }
}