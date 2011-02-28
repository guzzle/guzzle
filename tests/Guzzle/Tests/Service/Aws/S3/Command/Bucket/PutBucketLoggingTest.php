<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\S3Client;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketLoggingTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketLogging
     */
    public function testEnableLogging()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketLogging();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setTargetBucket('target'));
        $this->assertSame($command, $command->setTargetPrefix('logs_'));
        $this->assertSame($command, $command->addGrant(S3Client::GRANT_TYPE_GROUP, S3Client::GRANT_ALL, S3Client::GRANT_READ_ACP));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketLoggingResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertContains('PUT /?logging HTTP/1.1', $request);
        $this->assertContains('Host: test.s3.amazonaws.com', $request);
        $this->assertContains('<BucketLoggingStatus xmlns="http://doc.s3.amazonaws.com/2006-03-01"><LoggingEnabled><TargetPrefix>logs_</TargetPrefix><TargetBucket>target</TargetBucket><TargetGrants><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group"><URI>http://acs.amazonaws.com/groups/global/AllUsers</URI></Grantee><Permission>READ_ACP</Permission></Grant></TargetGrants></LoggingEnabled></BucketLoggingStatus>', $request);
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketLogging
     */
    public function testDisableLogging()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketLogging();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->disableLogging());

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketLoggingResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertContains('PUT /?logging HTTP/1.1', $request);
        $this->assertContains('Host: test.s3.amazonaws.com', $request);
        $this->assertEquals('<BucketLoggingStatus xmlns="http://doc.s3.amazonaws.com/2006-03-01"></BucketLoggingStatus>', (string)$command->getRequest()->getBody());
    }
}