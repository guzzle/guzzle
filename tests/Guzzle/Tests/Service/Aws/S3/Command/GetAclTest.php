<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetAclTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\GetAcl
     */
    public function testGetObjectAcl()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\GetAcl();
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $command->setBucket('test');
        $command->setKey('key');
        $this->setMockResponse($client, 'GetObjectAclResponse');

        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/key?acl', $command->getRequest()->getUrl());

        $acl = $command->getResult();
        $this->assertInstanceOf('Guzzle\\Service\\Aws\\S3\\Model\\Acl', $acl);

        $this->assertTrue($acl->getGrantList()->hasGrant('CanonicalUser', '8a6925ce4adf588a453214a379004fef'));
        $this->assertEquals('8a6925ce4adf588a4532aa379004fef', $acl->getOwnerId());
        $this->assertEquals('mtd@amazon.com', $acl->getOwnerDisplayName());
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\GetAcl
     */
    public function testGetBucketAcl()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\GetAcl();
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $command->setBucket('test');
        $this->setMockResponse($client, 'GetBucketAclResponse');

        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/?acl', $command->getRequest()->getUrl());

        $acl = $command->getResult();
        $this->assertInstanceOf('Guzzle\\Service\\Aws\\S3\\Model\\Acl', $acl);

        $this->assertTrue($acl->getGrantList()->hasGrant('CanonicalUser', '8a6925ce4adf57f21c32aa379004fef'));
        $this->assertEquals('8a6925ce4adee97f21c32aa379004fef', $acl->getOwnerId());
        $this->assertEquals('CustomersName@amazon.com', $acl->getOwnerDisplayName());
    }
}