<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Get the request payment configuration of  a bucket.
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTrequestPaymentGET.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 */
class GetBucketRequestPayment extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'));
        $this->request->getQuery()->set('requestPayment', false);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $xml = new \SimpleXMLElement($this->getResponse()->getBody(true));
        $this->result = (string)$xml->Payer;
    }

    /**
     * Returns the party responsible for paying for bucket requests
     *
     * @return string
     */
    public function getResult()
    {
        return parent::getResult();
    }
}