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
class PutBucketVersioningTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketVersioning
     */
    public function testEnableVersioning()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketVersioning();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setStatus(true));
        $this->assertSame($command, $command->setMfaDelete(true));
        $this->assertSame($command, $command->setMfaHeader('abc 123'));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketVersioningResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertContains('PUT /?versioning HTTP/1.1', $request);
        $this->assertContains('Host: test.s3.amazonaws.com', $request);
        $this->assertContains('x-amz-mfa: abc 123', $request);
        $this->assertContains('<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status><MfaDelete>Enabled</MfaDelete></VersioningConfiguration>', $request);
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketVersioning
     */
    public function testDisableVersioning()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketVersioning();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setStatus(false));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketVersioningResponse');
        $client->execute($command);

        $this->assertEquals('<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Suspended</Status></VersioningConfiguration>', (string)$command->getRequest()->getBody());
    }
}