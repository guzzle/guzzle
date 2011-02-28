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
 * This implementation of the PUT operation uses the requestPayment
 * sub-resource to set the request payment configuration of a bucket. By
 * default, the bucket owner pays for downloads from the bucket. This
 * configuration parameter enables the bucket owner (only) to specify that the
 * person requesting the download will be charged for the download. For more
 * information, see Requester Pays Buckets.
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTrequestPaymentPUT.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 * @guzzle payer required="true" doc="Party responsible for fees"
 */
class PutBucketRequestPayment extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'));
        $this->request->getQuery()->set('requestPayment', false);
        $this->request->setBody(EntityBody::factory(
            '<RequestPaymentConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Payer>'
            . $this->get('payer') 
            . '</Payer></RequestPaymentConfiguration>'
        ));
    }

    /**
     * Set the payer for the bucket
     *
     * @param string $payer Party responsible for fees.  One of Requester or BucketOwner
     *
     * @return PutBucketRequestPayment
     */
    public function setPayer($payer)
    {
        return $this->set('payer', $payer);
    }
}