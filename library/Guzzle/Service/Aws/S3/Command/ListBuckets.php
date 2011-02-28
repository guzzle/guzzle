<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Model\BucketList;
use Guzzle\Service\Command\AbstractCommand;

/**
 * List the buckets in your account.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ListBuckets extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        if ($this->getResponse()->isSuccessful()) {
            $this->result = new BucketList(new \SimpleXMLElement($this->getResponse()->getBody(true)));
        }
    }

    /**
     * Returns an BucketList model
     *
     * @return BucketList
     */
    public function getResult()
    {
        return parent::getResult();
    }
}