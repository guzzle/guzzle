<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Model;

use Guzzle\Service\Aws\S3\S3Client;

/**
 * Add, remove, and inspect grants
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GrantList implements \IteratorAggregate, \Countable
{
    /**
     * @var array
     */
    protected $grants = array();

    /**
     * Constructor
     *
     * @param \SimpleXMLElement|array $grantData XML data of a grant list or an
     *      array containing grant information
     */
    public function __construct($grantData = null)
    {
        if ($grantData) {
            if (is_array($grantData)) {
                $this->grants = $grantData;
            } else if ($grantData instanceof \SimpleXMLElement) {

                $nodes = $grantData->Grant;
                if (empty($nodes)) {
                    $nodes = new \SimpleXMLElement('<wrapper>' . str_replace('<?xml', '', $grantData->asXML()) . '</wrapper>');
                }
                
                foreach ($nodes as $grant) {
                    $children = $grant->Grantee->children();
                    $this->grants[] = array(
                        'type' => (string)$grant->Grantee->attributes('xsi', true)->type,
                        'permission' => (string)$grant->Permission,
                        'grantee' => (string)$children[0]
                    );
                }
            }
        }
    }

    /**
     * Get the grantlist as an XML string
     *
     * @return SimpleXMLElement
     */
    public function __toString()
    {
        $xml = '';
        foreach ($this->grants as $grant) {
            $xml .= '<Grant>';
            $xml .= '<Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="' . $grant['type'] . '">';            
            switch ($grant['type']) {
                case S3Client::GRANT_TYPE_EMAIL:
                    $xml .= '<EmailAddress>' . $grant['grantee'] . '</EmailAddress>';
                    break;
                case S3Client::GRANT_TYPE_USER:
                    $xml .= '<ID>' . $grant['grantee'] . '</ID>';
                    break;
                case S3Client::GRANT_TYPE_GROUP:
                    $xml .= '<URI>' . $grant['grantee'] . '</URI>';
                    break;
            }
            $xml .= '</Grantee>';
            $xml .= '<Permission>' . $grant['permission'] . '</Permission></Grant>';
        }
        
        return $xml;
    }

    /**
     * Get an ArrayIterator of the grants
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->grants);
    }

    /**
     * Get the number of grants in the list
     *
     * @return int
     */
    public function count()
    {
        return count($this->grants);
    }

    /**
     * Add a grant
     *
     * @param string $type The type to set: CanonicalUser | AmazonCustomerByEmail | Group
     * @param string $grantee The value to set for the $type.
     * @param string $permission The permission to grant: FULL_CONTROL | READ | WRITE
     *
     * @return GrantList
     */
    public function addGrant($type, $grantee, $permission)
    {
        if (!$this->hasGrant($type, $grantee, $permission)) {
            $this->grants[] = array(
                'type' => $type,
                'grantee' => $grantee,
                'permission' => $permission
            );
        }
        
        return $this;
    }

    /**
     * Get a grant from the list
     *
     * @param string $type Type to get: CanonicalUser | AmazonCustomerByEmail | Group
     * @param string $grantee The value to get for the $type.
     * @param string $permission The permission to get: FULL_CONTROL | READ | WRITE
     *
     * @return bool|array Returns FALSE if the grant was not found, or an
     *      associative array containing the grant information using the
     *      following keys: 'type', 'grantee', 'permission'
     */
    public function getGrant($type, $grantee, $permission)
    {
        foreach ($this->grants as $grant) {
            if ($grant['type'] == $type
                && $grant['grantee'] == $grantee
                && $grant['permission'] == $permission) {
                return $grant;
            }
        }
        
        return false;
    }

    /**
     * Check if the grant list contains a grant
     *
     * @param string $type The type to check: CanonicalUser | AmazonCustomerByEmail | Group
     * @param string $grantee (optional) The value to check for the $type.
     * @param string $permission (optional)  The permission to check: FULL_CONTROL | READ | WRITE
     */
    public function hasGrant($type, $grantee = null, $permission = null)
    {
        foreach ($this->grants as $grant) {
            if ($grant['type'] != $type) {
                continue;
            }
            if ($grantee && $grant['grantee'] != $grantee) {
                continue;
            }
            if ($permission && $grant['permission'] != $permission) {
                continue;
            }
            return true;
        }
        
        return false;
    }

    /**
     * Remove a grant
     *
     * @param string $type The type to remove: CanonicalUser | AmazonCustomerByEmail | Group
     * @param string $grantee (optional) The value to remove for the $type.
     * @param string $permission (optional) The permission to remove: FULL_CONTROL | READ | WRITE
     *
     * @return GrantList
     */
    public function removeGrant($type, $grantee = null, $permission = null)
    {
        foreach ($this->grants as $index => $grant) {
            if ($grant['type'] == $type
                && (!$grantee || $grant['grantee'] == $grantee)
                && (!$permission || $grant['permission'] == $permission)) {
                unset($this->grants[$index]);
            }
        }

        $this->grants = array_values($this->grants);
        
        return $this;
    }
}