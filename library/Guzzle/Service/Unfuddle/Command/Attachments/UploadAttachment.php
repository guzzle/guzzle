<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Attachments;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand;
use Guzzle\Http\EntityBody;

/**
 * Upload an Unfuddle attachment
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle body required="true" doc="Body of the attachment"
 * @guzzle type required="true" doc="Type of attachment (messages, tickets, tickets_comments, messages_comment, notebooks)"
 * @guzzle type_id required="true" doc="ID of the type"
 * @guzzle content_type doc="Content type"
 */
class UploadAttachment extends AbstractAttachmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('POST');
        parent::build();
        $this->request->setBody(EntityBody::factory($this->get('body')));
        $this->request->setHeader('Content-Type', $this->get('content_type'));
        $this->request->getQuery()->set('upload', false);
    }

    /**
     * Set the body of the attachment
     *
     * @param string|EntityBody|resource $body Body of the attachment
     *
     * @return UploadAttachment
     */
    public function setBody($body)
    {
        return $this->set('body', $body);
    }

    /**
     * Set the content type of the attachment
     *
     * @param string $contentType Content-Type to set
     *
     * @return UploadAttachment
     */
    public function setContentType($contentType)
    {
        return $this->set('content_type', $contentType);
    }
}