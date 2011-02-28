<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command;

use Guzzle\Service\Command\AbstractCommand;

/**
 * Base unfuddle command class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractUnfuddleCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        if ($this->hasKey('projects')) {
            $this->request->getQuery()->set('projects', $this->get('projects'));
        }
        
        // Unfuddle requires that the content-type be set as application/xml
        $this->request->setHeader('Content-Type', 'application/xml');
    }

    /**
     * Set the project ID of the command
     *
     * @param integer $id Project ID
     *
     * @return AbstractUnfuddleCommand
     */
    public function setProjectId($id)
    {
        return $this->set('projects', (int)$id);
    }
}