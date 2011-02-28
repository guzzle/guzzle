<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Tickets;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Create an Unfuddle ticket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle projects required="true" doc="Project ID"
 */
class CreateTicket extends AbstractTicketBodyCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('POST');
        parent::build();
        $this->request->getQuery()->add('tickets', false);
    }
}