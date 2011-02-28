<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Model;

use Guzzle\Service\Aws\S3\Model\Acl;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AclTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Model\Acl::__construct
     * @covers Guzzle\Service\Aws\S3\Model\Acl::getOwnerId
     * @covers Guzzle\Service\Aws\S3\Model\Acl::setOwnerId
     * @covers Guzzle\Service\Aws\S3\Model\Acl::getOwnerDisplayName
     * @covers Guzzle\Service\Aws\S3\Model\Acl::setOwnerDisplayName
     * @covers Guzzle\Service\Aws\S3\Model\Acl::__toString
     */
    public function testHoldsOwnerInformation()
    {
        $acl = new Acl();

        // Test the default owner info is an empty string
        $this->assertEquals('', $acl->getOwnerDisplayName());
        $this->assertEquals('', $acl->getOwnerId());

        // Set and test the Owner information
        $this->assertSame($acl, $acl->setOwnerDisplayName('test'));
        $this->assertSame($acl, $acl->setOwnerId('test_id'));
        $this->assertEquals('test', $acl->getOwnerDisplayName());
        $this->assertEquals('test_id', $acl->getOwnerId());

        // Make sure that the Owner ID is carried over to the generated ACL
        $a = new \SimpleXMLElement((string)$acl);

        $this->assertEquals('test_id', (string)$a->Owner->ID);
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Model\Acl::__construct
     */
    public function testCanBuildFromExisting()
    {
        $xml = new \SimpleXMLElement('<AccessControlPolicy><Owner><ID>test_id</ID><DisplayName>Bob</DisplayName></Owner><AccessControlList><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group"><URI>http://acs.amazonaws.com/groups/global/AuthenticatedUsers</URI></Grantee><Permission>FULL_CONTROL</Permission></Grant></AccessControlList></AccessControlPolicy>');

        $acl = new Acl($xml);
        $this->assertEquals('Bob', $acl->getOwnerDisplayName());
        $this->assertEquals('test_id', $acl->getOwnerId());

        $this->assertTrue($acl->getGrantList()->hasGrant('Group', \Guzzle\Service\Aws\S3\S3Client::GRANT_AUTH, \Guzzle\Service\Aws\S3\S3Client::GRANT_FULL_CONTROL));
    }
}