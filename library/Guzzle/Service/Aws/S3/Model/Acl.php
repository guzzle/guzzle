<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Model;

/**
 * Amazon S3 ACL model
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Acl
{
    /**
     * @var GrantList
     */
    protected $grantList;

    /**
     * @var string
     */
    protected $ownerId = '';

    /**
     * @var string
     */
    protected $ownerDisplayName = '';

    /**
     * Constructor
     *
     * @param \SimpleXMLElement $acl (optional) ACL XML data
     */
    public function __construct(\SimpleXMLElement $acl = null)
    {
        if ($acl) {
            $this->ownerId = (string)$acl->Owner->ID;
            $this->ownerDisplayName = (string)$acl->Owner->DisplayName;
            $this->grantList = new GrantList($acl->AccessControlList);
        } else {
            $this->grantList = new GrantList();
        }
    }

    /**
     * Get the ACL as an XML string
     *
     * @return string
     */
    public function __toString()
    {
        $xml = sprintf('<AccessControlPolicy><Owner><ID>%s</ID></Owner>', $this->ownerId);
        $xml .= sprintf('<AccessControlList>%s</AccessControlList></AccessControlPolicy>', (string) $this->grantList);

        return $xml;
    }

    /**
     * Get the owner ID
     *
     * @return string
     */
    public function getOwnerId()
    {
        return $this->ownerId;
    }

    /**
     * Set the owner ID
     *
     * @param string $ownerId The owner ID to set
     *
     * @return Acl
     */
    public function setOwnerId($ownerId)
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    /**
     * Get the owner DisplayName
     *
     * @return string
     */
    public function getOwnerDisplayName()
    {
        return $this->ownerDisplayName;
    }

    /**
     * Set the owner DisplayName
     *
     * @param string $displayName The display name to set
     *
     * @return Acl
     */
    public function setOwnerDisplayName($displayName)
    {
        $this->ownerDisplayName = $displayName;
        
        return $this;        
    }

    /**
     * Get the grant list used in the ACL
     *
     * @return GrantList
     */
    public function getGrantList()
    {
        return $this->grantList;
    }
}