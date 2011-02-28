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
 * Set the notification settings of a bucket
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketPUTnotification.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket doc="Bucket to set the notification on" required="true"
 * @guzzle notification doc="XML Bucket notification settings" required="true"
 */
class PutBucketNotification extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'));
        $this->request->getQuery()->set('notification', false);
        $this->request->setBody(EntityBody::factory($this->get('notification')));
    }

    /**
     * Set the bucket notification settings
     *
     * @param string|SimpleXMLElement $notification Notification settings to set
     *
     * @return PutBucketNotification
     */
    public function setNotification($notification)
    {
        if ($notification instanceof \SimpleXMLElement) {
            $xml = $notification->asXML();
            $xml = implode("\n", array_slice(explode("\n", $xml), 1));
        } else {
            $xml = $notification;
        }

        return $this->set('notification', $xml);
    }
}