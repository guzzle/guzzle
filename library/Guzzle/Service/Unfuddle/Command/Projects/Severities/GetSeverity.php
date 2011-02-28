<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Projects\Severities;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Get the severities associated with a ticket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetSeverity extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        parent::build();
        if ($this->hasKey('id')) {
            $this->request->getQuery()->add('severities', $this->get('id', ''));
        } else {
            $this->request->getQuery()->add('severities', false);
        }
    }

    /**
     * Set the severity ID of the command
     *
     * @param integer $id The severity ID to retrieve
     *
     * @return GetSeverity
     */
    public function setSeverityId($id)
    {
        return $this->set('id', (int)$id);
    }
}