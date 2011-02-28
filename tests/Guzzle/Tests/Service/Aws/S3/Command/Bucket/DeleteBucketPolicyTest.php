<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Guzzle;
use Guzzle\Service\Aws\S3\Command\Bucket\DeleteBucketPolicy;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DeleteBucketPolicyTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\DeleteBucketPolicy
     */
    public function testDeleteBucketPolicy()
    {
        $command = new DeleteBucketPolicy();
        $this->assertSame($command, $command->setBucket('test'));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'DeleteBucketPolicyResponse');
        $client->execute($command);

        // Ensure that the DELETE request was sent to the policy sub resource
        $this->assertEquals('http://test.s3.amazonaws.com/?policy', $command->getRequest()->getUrl());
        $this->assertEquals('DELETE', $command->getRequest()->getMethod());

        // Check the raw HTTP request message
        $request = explode("\r\n", (string) $command->getRequest());
        $this->assertEquals('DELETE /?policy HTTP/1.1', $request[0]);
        $this->assertEquals('User-Agent: ' . Guzzle::getDefaultUserAgent(), $request[1]);
        $this->assertEquals('Host: test.s3.amazonaws.com', $request[2]);
        $this->assertContains("Date: ", $request[3]);
        $this->assertContains("Authorization: ", $request[4]);
    }
}