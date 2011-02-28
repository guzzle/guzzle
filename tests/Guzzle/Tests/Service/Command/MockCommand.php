<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Command;

/**
 * Mock Command
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle test default="123" required="true" doc="Test argument"
 */
class MockCommand extends \Guzzle\Service\Command\AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
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