<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\Request;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * Abstract class to simplify HEAD object and GET object commands
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractRequestObjectPut extends AbstractRequestObject
{
    /**
     * Set the canned ACL to apply to the object
     *
     * @param string $acl The ACL to set: private | public-read | public-read-write |
     *      authenticated-read | bucket-owner-read | bucket-owner-full-control
     *
     * @return AbstractRequestObjectPut
     */
    public function setAcl($acl)
    {
        return $this->set('acl', $acl);
    }

    /**
     * Set the storage class of the object
     *
     * @param string $storageClass RRS enables customers to reduce their costs
     *      by storing non-critical, reproducible data at lower levels of
     *      redundancy than Amazon S3's standard storage.  One of
     *      STANDARD | REDUCED_REDUNDANCY
     *
     * @return AbstractRequestObjectPut
     *
     * @throws InvalidArgumentException If the storage class is not a valid option
     */
    public function setStorageClass($storageClass)
    {
        $storageClass = strtoupper($storageClass);

        if ($storageClass != 'STANDARD' && $storageClass != 'REDUCED_REDUNDANCY') {
            throw new \InvalidArgumentException('$storageClass must be one of STANDARD or REDUCED_REDUNDANCY');
        }

        return $this->set('storage_class', $storageClass);
    }

    /**
     * Apply any set default object parameters to a request
     *
     * @param RequestInterface $request Request object to apply to
     */
    protected function applyDefaults(RequestInterface $request)
    {
        parent::applyDefaults($request);

        // If an ACL has been specified, set it on the request
        if ($this->get('acl')) {
            $request->setHeader('x-amz-acl', $this->get('acl'));
        }

        // If a storage class has been specified, set it on the request
        if ($this->get('storage_class')) {
            $request->setHeader('x-amz-storage-class', $this->get('storage_class'));
        }
    }
}