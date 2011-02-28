<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Projects\Versions;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Get the versions associated with a ticket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetVersion extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        parent::build();
        if ($this->hasKey('id')) {
            $this->request->getQuery()->add('versions', $this->get('id', ''));
        } else {
            $this->request->getQuery()->add('versions', false);
        }
    }

    /**
     * Set the version ID of the command
     *
     * @param integer $id Version ID to retrieve
     *
     * @return GetVersion
     */
    public function setVersionId($id)
    {
        return $this->set('id', (int)$id);
    }
}