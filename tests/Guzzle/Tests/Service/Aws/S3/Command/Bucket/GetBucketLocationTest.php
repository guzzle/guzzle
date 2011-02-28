<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetBucketLocationTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\GetBucketLocation
     */
    public function testGetBucketLocation()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\GetBucketLocation();
        $command->setBucket('test');
        
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'GetBucketLocationResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/?location', $command->getRequest()->getUrl());
        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertEquals('EU', $command->getResult());
    }
}