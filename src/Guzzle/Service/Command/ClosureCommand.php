<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\RequestInterface;

/**
 * A ClosureCommand is a command that allows dynamic commands to be created at
 * runtime using a closure to prepare the request.  A closure key and \Closure
 * value must be passed to the command in the constructor.  The closure must
 * accept the command object as an argument.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClosureCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException if a closure was not passed
     */
    protected function init()
    {
        if (!$this->get('closure')) {
            throw new \InvalidArgumentException('A closure must be passed in the parameters array');
        }

        if (!$this->get('closure_api')) {
            throw new \InvalidArgumentException('A closure_api value must be passed in the parameters array');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnexpectedValueException If the closure does not return a request
     */
    protected function build()
    {
        $closure = $this->get('closure');
        $this->request = $closure($this, $this->get('closure_api'));

        if (!$this->request || !$this->request instanceof RequestInterface) {
            throw new \UnexpectedValueException('Closure command did not return a RequestInterface object');
        }
    }

    /**
     * Set whether or not the command can be batched
     *
     * @param bool $canBatch Set to TRUE if you can batch this command or FALSE
     *
     * @return ClosureCommand
     */
    public function setCanBatch($canBatch)
    {
        $this->canBatch = (bool) $canBatch;

        return $this;
    }
}