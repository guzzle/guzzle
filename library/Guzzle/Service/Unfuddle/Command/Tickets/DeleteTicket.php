<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Tickets;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Delete an Unfuddle ticket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle id required="true" doc="ID of the ticket to delete"
 * @guzzle projects required="true" doc="Project ID"
 */
class DeleteTicket extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('DELETE');
        parent::build();
        $this->request->getQuery()->add('tickets', $this->get('id'));
    }

    /**
     * Set the ticket ID of the command
     *
     * @param integer $id The ticket ID
     *
     * @return DeleteTicket
     */
    public function setId($id)
    {
        return $this->set('id', (int)$id);
    }
}