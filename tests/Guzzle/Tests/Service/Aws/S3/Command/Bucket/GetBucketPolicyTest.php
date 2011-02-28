<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\Command\Bucket\GetBucketPolicy;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetBucketPolicyTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\GetBucketPolicy
     */
    public function testGetBucketPolicy()
    {
        $command = new GetBucketPolicy();
        $command->setBucket('test');

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'GetBucketPolicyResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/?policy', $command->getRequest()->getUrl());
        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertInternalType('array', $command->getResult());

        $policy = $command->getResult();
        $this->assertArrayHasKey('Version', $policy);
        $this->assertEquals('2008-10-17', $policy['Version']);
    }
}