<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Messages;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Delete an Unfuddle message
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle id required="true" doc="A message ID is required"
 */
class DeleteMessage extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('DELETE');
        parent::build();
        $this->request->getQuery()->add('messages', $this->get('id', ''));
    }

    /**
     * Set the message ID of the message to delete
     *
     * @param integer $id Message ID
     *
     * @return DeleteMessage
     */
    public function setId($id)
    {
        return $this->set('id', (int)$id);
    }
}