<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Attachments;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleBodyCommand;

/**
 * Abstract class to create or modify an Unfuddle attachment
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractAttachmentCommand extends AbstractUnfuddleBodyCommand
{
    /**
     * {@inheritdoc}
     */
    protected $containingElement = 'attachment';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        $q = $this->request->getQuery();

        $path = explode('_', $this->get('type'));
        if (count($path) == 2) {
            $q->set($path[0], false)->set($path[1], $this->get('type_id'));
        } else {
            $q->set($path[0], $this->get('type_id'));
        }

        if ($this->get('id')) {
            $q->set('attachments', $this->get('id'));
        } else {
            $q->set('attachments', false);
        }
    }

    /**
     * Set the ID of the attachment
     *
     * @param string $id ID of the attachment to delete
     *
     * @return AbstractAttachmentCommand
     */
    public function setId($id)
    {
        return $this->set('id', $id);
    }

    /**
     * Set the type and type ID of the attachment (e.g. ticket ID)
     *
     * @param string $type One of messages, tickets, tickets_comments, messages_comment, notebooks
     * @param string $typeId ID of the type
     *
     * @return AbstractAttachmentCommand
     */
    public function setTypeId($type, $typeId)
    {
        return $this->set('type', $type)->set('type_id', $typeId);
    }
}