<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\Object\AbstractRequestObject;
use Guzzle\Service\Aws\S3\Model\Acl;
use Guzzle\Http\EntityBody;

/**
 * Set the ACL of an object or bucket
 *
 * @guzzle bucket doc="Bucket" required="true"
 * @guzzle acl doc="ACL to set" required="true" type="class:Guzzle\Service\Aws\S3\Model\Acl"
 * @guzzle key doc="Object key (optional)"
 * @guzzle version_id doc="Version ID to set"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutAcl extends AbstractRequestObject
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'), $this->get('key'));
        $this->request->getQuery()->set('acl', false);
        $this->request->setBody(EntityBody::factory((string)$this->get('acl')));

        // Add the versionId if setting an ACL of an object
        if ($this->get('key') && $this->get('version_id')) {
            $this->request->getQuery()->set('versionId', $this->get('version_id'));
        }
    }

    /**
     * Set the ACL of the PutAcl command
     *
     * @param Acl $acl The ACL to set
     *
     * @return PutAcl
     */
    public function setAcl(Acl $acl)
    {
        return $this->set('acl', $acl);
    }

    /**
     * Set the version ID of the object to set the ACL
     *
     * @param string $versionId
     *
     * @return PutAcl
     */
    public function setVersionId($versionId)
    {
        return $this->set('version_id', $versionId);
    }
}