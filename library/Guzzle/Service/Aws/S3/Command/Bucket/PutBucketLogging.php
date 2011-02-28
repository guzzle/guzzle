<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;
use Guzzle\Service\Aws\S3\Model\GrantList;

/**
 * Set the logging options on a bucket
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketPUTlogging.html
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketLogging extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        $this->set('grants', new GrantList());
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'));
        $this->request->getQuery()->set('logging', false);

        $config = '<BucketLoggingStatus xmlns="http://doc.s3.amazonaws.com/2006-03-01">';

        if (!$this->get('disable_logging')) {
            
            $config .= '<LoggingEnabled>';

            if ($this->hasKey('target_prefix')) {
                $config .= '<TargetPrefix>' . htmlspecialchars($this->get('target_prefix')) . '</TargetPrefix>';
            }

            if ($this->hasKey('target_bucket')) {
                $config .= '<TargetBucket>' . htmlspecialchars($this->get('target_bucket')) . '</TargetBucket>';
            }

            $config .= '<TargetGrants>' . $this->get('grants') . '</TargetGrants>';
            $config .= '</LoggingEnabled>';
        }

        $config .= '</BucketLoggingStatus>';

        $this->request->setBody(\Guzzle\Http\EntityBody::factory($config));
    }

    /**
     * Add a grantee to the bucket's logging data
     *
     * @param string $type Type to set: CanonicalUser | AmazonCustomerByEmail | Group
     * @param string $grantee The value to set for the $type.
     * @param string $permission The permission to grant: FULL_CONTROL | READ | WRITE
     *
     * @return PutBucketLogging
     */
    public function addGrant($type, $grantee, $permission)
    {
        $this->get('grants')->addGrant($type, $grantee, $permission);
        
        return $this;
    }

    /**
     * Disable logging for the bucket
     *
     * @return PutBucketLogging
     */
    public function disableLogging()
    {
        return $this->set('disable_logging', true);
    }

    /**
     * Set the target bucket for logging.
     *
     * Specifies the bucket where you want Amazon S3 to store server access
     * logs. You can have your logs delivered to any bucket that you own,
     * including the same bucket that is being logged. You can also configure
     * multiple buckets to deliver their logs to the same target bucket. In
     * this case you should choose a different TargetPrefix for each source
     * bucket so that the delivered log files can be distinguished by key.
     *
     * @param string $targetBucket The target bucket
     *
     * @return PutBucketLogging
     */
    public function setTargetBucket($targetBucket)
    {
        return $this->set('target_bucket', $targetBucket);
    }

    /**
     * Add a prefix for the keys that the log files will be stored under.
     *
     * @param string $targetPrefix The prefix to add to log files
     *
     * @return PutBucketLogging
     */
    public function setTargetPrefix($targetPrefix)
    {
        return $this->set('target_prefix', $targetPrefix);
    }
}