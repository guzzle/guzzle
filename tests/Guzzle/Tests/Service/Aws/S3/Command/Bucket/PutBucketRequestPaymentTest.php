<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketRequestPaymentTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketRequestPayment
     */
    public function testPutBucketRequestPayment()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketRequestPayment();
        $command->setBucket('test');
        $command->setPayer('Requester');

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketRequestPaymentResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/?requestPayment', $command->getRequest()->getUrl());
        $this->assertEquals('PUT', $command->getRequest()->getMethod());

        $this->assertEquals(
            '<RequestPaymentConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Payer>Requester</Payer></RequestPaymentConfiguration>',
            (string)$command->getRequest()->getBody()
        );
    }
}