<?php

namespace Guzzle\Tests\Service\Mock\Command;

/**
 * Mock Command
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle test default="123" required="true" doc="Test argument"
 * @guzzle other
 * @guzzle _internal default="abc"
 */
class MockCommand extends \Guzzle\Service\Command\AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->createRequest();
    }

    /**
     * Set whether or not the command can be batched
     *
     * @param bool $canBatch
     *
     * @return MockCommand
     */
    public function setCanBatch($canBatch)
    {
        $this->canBatch = $canBatch;

        return $this;
    }
}