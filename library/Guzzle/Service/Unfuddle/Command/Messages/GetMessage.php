<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Messages;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Get an Unfuddle message or messages
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetMessage extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        parent::build();
        $this->request->getQuery()->add('messages', $this->get('id', ''));
    }

    /**
     * Set the message ID of the command
     *
     * @param integer $id Message ID to retrieve
     *
     * @return GetMessage
     */
    public function setId($id)
    {
        return $this->set('id', (int)$id);
    }
}