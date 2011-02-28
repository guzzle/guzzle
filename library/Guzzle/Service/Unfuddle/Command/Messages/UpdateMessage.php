<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Messages;

/**
 * Update an Unfuddle message
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle id required="true" doc="Message ID"
 */
class UpdateMessage extends AbstractMessageBodyCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('PUT');
        parent::build();

        $this->request->getQuery()->set('messages', $this->get('id', ''));
    }

    /**
     * Set the message ID of the command
     *
     * @param integer $id Message ID to update
     *
     * @return UpdateMessage
     */
    public function setId($id)
    {
        return $this->set('id', (int)$id);
    }
}