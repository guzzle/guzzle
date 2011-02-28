<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucket
     */
    public function testPutBucket()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucket();
        $command->setBucket('test');
        $command->setAcl('public-read');
        $command->setRegion('us-west-1');

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/', $command->getRequest()->getUrl());
        $this->assertEquals('PUT', $command->getRequest()->getMethod());

        $this->assertEquals(
            '<?xml version="1.0"?>' . "\n"
            . '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>us-west-1</LocationConstraint></CreateBucketConfiguration>',
            (string)$command->getRequest()->getBody()
        );
    }
}