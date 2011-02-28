<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Command\AbstractCommand;

/**
 * Abstract Amazon S3 command which interacts with buckets.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractS3BucketCommand extends AbstractCommand
{
    /**
     * Set the bucket name where the object is stored
     *
     * @param string $bucket Mame of the bucket that the object is stored in
     *
     * @return GetObject
     */
    public function setBucket($bucket)
    {
        return $this->set('bucket', $bucket);
    }
}