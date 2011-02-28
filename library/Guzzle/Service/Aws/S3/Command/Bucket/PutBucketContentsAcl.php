<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\ResourceIteratorApplyBatched;
use Guzzle\Service\Aws\S3\Command\PutAcl;
use Guzzle\Service\Aws\S3\Model\Acl;

/**
 * Set an ACL on each object within a bucket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true" doc="Bucket to iterate"
 * @guzzle acl required="true" doc="ACL to set on all objects in the bucket"
 */
class PutBucketContentsAcl extends ListBucket
{
    /**
     * {@inheritdoc}
     */
    protected $canBatch = false;

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        parent::process();

        $self = $this;

        $clear = new ResourceIteratorApplyBatched($this->getResult(), function($iterator, $batched) use ($self) {

            $set = new CommandSet();
            foreach ($batched as $key) {
                $set->addCommand(new PutAcl(array(
                    'bucket' => $iterator->getBucketName(),
                    'acl' => $self->get('acl')
                )));
            }
            
            $self->getClient()->execute($set);
        });

        // Set the number of iterated objects
        $clear->apply();
        
        $this->result = $clear;
    }

    /**
     * Set the ACL to place on each object in the bucket
     *
     * @param Acl $acl The ACL to set
     *
     * @return PutBucketsContentsAcl
     */
    public function setAcl(Acl $acl)
    {
        return $this->set('acl', $acl);
    }

    /**
     * Returns the batch applicator object
     *
     * @return ResourceIteratorApplyBatched
     */
    public function getResult()
    {
        return parent::getResult();
    }
}