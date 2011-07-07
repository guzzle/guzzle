<?php

namespace Guzzle\Service;

use Guzzle\Common\Event\AbstractSubject;

/**
 * Apply a callback to the contents of a {@see ResourceIterator}
 *
 * Signals emitted:
 *
 *  event         context  description
 *  -----         -------  -----------
 *  before_batch  array    About to send a batch of requests to the callback
 *  after_batch   array    Finished sending a batch of requests to the callback
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ResourceIteratorApplyBatched extends AbstractSubject
{
    /**
     * @var function|array
     */
    protected $callback;

    /**
     * @var ResourceIterator
     */
    protected $iterator;

    /**
     * @var integer Total number of sent batches
     */
    protected $batches = 0;

    /**
     * @var int Total number of iterated resources
     */
    protected $iterated = 0;

    /**
     * Constructor
     *
     * @param ResourceIterator $iterator Resource iterator to apply a callback to
     * @param array|function $callback Callback method accepting the resource
     *      iterator and an array of the iterator's current resources
     */
    public function __construct(ResourceIterator $iterator, $callback)
    {
        $this->iterator = $iterator;
        $this->callback = $callback;
    }

    /**
     * Apply the callback to the contents of the resource iterator
     *
     * Calling this method dispatches four events:
     *
     *   # before_apply -- Before adding a resource to a batch.  Context is the resource
     *   # after_apply -- After adding the resource to a batch.  Context is the resource
     *   # before_batch -- Before a batch request is sent if one is sent.  Context is an array of resources
     *   # after_batch -- After a batch request is sent if one is sent.  Context is an array of resources
     *
     * @return integer Returns the number of resources iterated
     */
    public function apply($perBatch = 20)
    {
        if ($this->iterated == 0) {
            $batched = array();
            $this->iterated = 0;

            foreach ($this->iterator as $resource) {
                $batched[] = $resource;
                if (count($batched) >= $perBatch) {
                    $this->applyBatch($batched);
                    $batched = array();
                }
                $this->iterated++;
            }

            if (count($batched)) {
                $this->applyBatch($batched);
            }

            unset($batch);
        }

        return $this->iterated;
    }

    /**
     * Get the total number of batches sent
     *
     * @return int
     */
    public function getBatchCount()
    {
        return $this->batches;
    }

    /**
     * Get the total number of iterated resources
     *
     * @return int
     */
    public function getIteratedCount()
    {
        return $this->iterated;
    }

    /**
     * Apply the callback to a collection of resources
     *
     * @param array $batch
     */
    private function applyBatch(array $batch)
    {
        $this->batches++;

        $this->getEventManager()->notify('before_batch', $batch);
        call_user_func_array($this->callback, array(
            $this->iterator, $batch
        ));
        $this->getEventManager()->notify('after_batch', $batch);
    }
}