<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\People;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Get all people
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetPeople extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        parent::build();
        $this->request->getQuery()->add('people', false);
    }
}