<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class HeadBucketTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\HeadBucket
     */
    public function testHeadBucket()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\HeadBucket();
        $command->setBucket('test');

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'DefaultResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/?max-keys=0', $command->getRequest()->getUrl());
        $this->assertEquals('HEAD', $command->getRequest()->getMethod());
    }
}