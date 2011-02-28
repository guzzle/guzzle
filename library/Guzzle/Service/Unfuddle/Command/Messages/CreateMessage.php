<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Messages;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Create an Unfuddle message
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CreateMessage extends AbstractMessageBodyCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('POST');
        parent::build();
        $this->request->getQuery()->add('messages', false);
    }
}