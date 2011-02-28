<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\ResourceIteratorApplyBatched;
use Guzzle\Service\Aws\S3\Command\Object\DeleteObject;

/**
 * Delete all objects from a bucket
 *
 * Objects are first retrieved from the bucket using whatever optional prefix or
 * delimiter is added to the command.  A {@see BucketApplyBatched} object is
 * created to iterate over the contents of _every_ matching object and issues a
 * DELETE command on each object using parallel requests.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 * @guzzle per_batch doc="Number of items to delete per batch request"
 */
class ClearBucket extends ListBucket
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
            if (count($batched)) {
                $set = new CommandSet();
                foreach ($batched as $key) {
                    $set->addCommand(new DeleteObject(array(
                        'bucket' => $iterator->getBucketName(),
                        'key' => $key['key']
                    )));
                }
                $self->getClient()->execute($set);
            }
        });

        $clear->apply($this->get('per_batch', 20));
        
        $this->result = $clear;
    }

    /**
     * Returns the ResourceIteratorApplyBatched object
     *
     * @return ResourceIteratorApplyBatched
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Set the number of objects to delete per batch request
     *
     * @param int $perBatch Items to delete per batched request
     *
     * @return ClearBucket
     */
    public function setPerBatch($perBatch)
    {
        return $this->set('per_batch', $perBatch);
    }
}