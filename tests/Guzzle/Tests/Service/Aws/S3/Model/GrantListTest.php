<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Model;

use Guzzle\Service\Aws\S3\Model\Acl;
use Guzzle\Service\Aws\S3\Model\GrantList;
use Guzzle\Service\Aws\S3\S3Client;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GrantListTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Model\GrantList::__construct
     */
    public function testCanBuildFromExisting()
    {
        $l = new GrantList(array(
            array(
                'type' => 'Group',
                'grantee' => \Guzzle\Service\Aws\S3\S3Client::GRANT_AUTH,
                'permission' => 'FULL_CONTROL'
            )
        ));

        $this->assertTrue($l->hasGrant('Group', \Guzzle\Service\Aws\S3\S3Client::GRANT_AUTH, 'FULL_CONTROL'));

        $l = new GrantList(new \SimpleXMLElement('<Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group"><URI>http://acs.amazonaws.com/groups/global/AuthenticatedUsers</URI></Grantee><Permission>FULL_CONTROL</Permission></Grant>'));
        $this->assertTrue($l->hasGrant('Group', \Guzzle\Service\Aws\S3\S3Client::GRANT_AUTH, 'FULL_CONTROL'));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Model\GrantList
     */
    public function testHandlesGrantStorage()
    {
        $l = new GrantList();

        $this->assertSame($l, $l->addGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_AUTH, S3Client::GRANT_READ));
        $this->assertSame($l, $l->addGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL, S3Client::GRANT_READ_ACP));
        $this->assertSame($l, $l->addGrant(S3Client::GRANT_TYPE_EMAIL, 'michael@test.com', S3Client::GRANT_FULL_CONTROL));
        $this->assertSame($l, $l->addGrant(S3Client::GRANT_TYPE_EMAIL, 'moko@test.com', S3Client::GRANT_READ));
        $this->assertSame($l, $l->addGrant(S3Client::GRANT_TYPE_USER, 'abc123', S3Client::GRANT_LOG));

        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_AUTH));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_AUTH, S3Client::GRANT_READ));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, null, S3Client::GRANT_READ));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL, S3Client::GRANT_READ_ACP));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, null, S3Client::GRANT_READ_ACP));

        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'michael@test.com'));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'moko@test.com'));
        $this->assertFalse($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'nope@nope.com'));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'michael@test.com', S3Client::GRANT_FULL_CONTROL));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'moko@test.com', S3Client::GRANT_READ));

        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_USER));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_USER, 'abc123'));
        $this->assertFalse($l->hasGrant(S3Client::GRANT_TYPE_USER, 'foobar'));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_USER, 'abc123', S3Client::GRANT_LOG));
        $this->assertFalse($l->hasGrant(S3Client::GRANT_TYPE_USER, 'abc123', S3Client::GRANT_FULL_CONTROL));
        
        // Get the total number of grants
        $this->assertEquals(5, count($l));

        $this->assertInstanceOf('ArrayIterator', $l->getIterator());
        $this->assertEquals(5, count($l->getIterator()));
        
        // Get grant data for a specific grant
        $this->assertEquals(array(
            'type' => S3Client::GRANT_TYPE_EMAIL,
            'grantee' => 'michael@test.com',
            'permission' => S3Client::GRANT_FULL_CONTROL
        ), $l->getGrant(S3Client::GRANT_TYPE_EMAIL, 'michael@test.com', S3Client::GRANT_FULL_CONTROL));

        // Returns false when a grant is not found
        $this->assertFalse($l->getGrant('a', 'b', 'c'));

        // Remove all grants by type
        $this->assertSame($l, $l->removeGrant(S3Client::GRANT_TYPE_USER));
        $this->assertFalse($l->hasGrant(S3Client::GRANT_TYPE_USER));

        // Remove all grants by type and value
        $this->assertSame($l, $l->removeGrant(S3Client::GRANT_TYPE_EMAIL, 'moko@test.com'));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL));
        $this->assertFalse($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'moko@test.com'));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_EMAIL, 'michael@test.com', S3Client::GRANT_FULL_CONTROL));

        // Remove all grants by type, name, and value
        $this->assertSame($l, $l->removeGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_AUTH, S3Client::GRANT_READ));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP));
        $this->assertFalse($l->hasGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_AUTH, S3Client::GRANT_READ));
        $this->assertTrue($l->hasGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL, S3Client::GRANT_READ_ACP));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Model\GrantList::__toString
     */
    public function testCanRepresentAsString()
    {
        $l = new GrantList();
        $l->addGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_AUTH, S3Client::GRANT_READ);
        $l->addGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL, S3Client::GRANT_READ_ACP);
        $l->addGrant(S3Client::GRANT_TYPE_EMAIL, 'michael@test.com', S3Client::GRANT_FULL_CONTROL);
        $l->addGrant(S3Client::GRANT_TYPE_EMAIL, 'moko@test.com', S3Client::GRANT_READ);
        $l->addGrant(S3Client::GRANT_TYPE_USER, 'abc123', S3Client::GRANT_LOG);

        $this->assertEquals('<Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group"><URI>http://acs.amazonaws.com/groups/global/AuthenticatedUsers</URI></Grantee><Permission>READ</Permission></Grant><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group"><URI>http://acs.amazonaws.com/groups/global/AllUsers</URI></Grantee><Permission>READ_ACP</Permission></Grant><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="AmazonCustomerByEmail"><EmailAddress>michael@test.com</EmailAddress></Grantee><Permission>FULL_CONTROL</Permission></Grant><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="AmazonCustomerByEmail"><EmailAddress>moko@test.com</EmailAddress></Grantee><Permission>READ</Permission></Grant><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser"><ID>abc123</ID></Grantee><Permission>http://acs.amazonaws.com/groups/s3/LogDelivery</Permission></Grant>', (string)$l);
    }
}