<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Projects\Components;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;

/**
 * Get the components associated with a ticket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetComponent extends AbstractUnfuddleCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        parent::build();
        if ($this->hasKey('id')) {
            $this->request->getQuery()->add('components', $this->get('id', ''));
        } else {
            $this->request->getQuery()->add('components', false);
        }
    }

    /**
     * Set the component ID of the command
     *
     * @param integer $id Component ID to retrieve
     *
     * @return GetComponent
     */
    public function setCompnentId($id)
    {
        return $this->set('id', (int)$id);
    }
}