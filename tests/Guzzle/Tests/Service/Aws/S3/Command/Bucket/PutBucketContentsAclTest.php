<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\Model\Acl;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\Bucket\PutBucketContentsAcl;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketContentsAclTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketContentsAcl
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketContentsAcl::process
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketContentsAcl::setAcl
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketContentsAcl::getResult
     * @covers Guzzle\Service\ResourceIteratorApplyBatched
     */
    public function testIterativeAclSet()
    {
        $command = new PutBucketContentsAcl();
        $command->setBucket('test');

        $acl = new Acl();
        $acl->getGrantList()->addGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL, S3Client::GRANT_READ);

        $this->assertSame($command, $command->setAcl($acl));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        
        $this->setMockResponse($client, array(
            'ListBucketNextMarkerPrefixMarkerResponse',
            'ListBucketResponse',
            'PutObjectAclResponse',
            'PutObjectAclResponse',
            'PutObjectAclResponse',
            'PutObjectAclResponse'

        ));

        $client->execute($command);

        $requests = $this->getMockedRequests();

        // Two list buckets followed by deletes for each key found in the results
        $this->assertEquals('GET', $requests[0]->getMethod());
        $this->assertEquals('GET', $requests[1]->getMethod());

        $this->assertEquals('PUT', $requests[2]->getMethod());
        $this->assertEquals('/?acl', $requests[2]->getResourceUri());

        $this->assertEquals('PUT', $requests[3]->getMethod());
        $this->assertEquals('/?acl', $requests[3]->getResourceUri());

        $this->assertEquals('PUT', $requests[4]->getMethod());
        $this->assertEquals('/?acl', $requests[4]->getResourceUri());

        $this->assertEquals('PUT', $requests[5]->getMethod());
        $this->assertEquals('/?acl', $requests[5]->getResourceUri());

        $this->assertEquals(4, $command->getResult()->getIteratedCount());
        $this->assertEquals(1, $command->getResult()->getSentPoolCount());
    }
}