<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\People;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Get the person who is currently accessing the API.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetCurrentPerson extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        $this->request->getQuery()->set('people', 'current');
    }
}