<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3;

use Guzzle\Service\Aws\S3\S3Client;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class S3ClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * Data provider for testing if a bucket name is valid
     * 
     * @return array
     */
    public function bucketNameProvider()
    {
        return array(
            array('.bucket', false),
            array('bucket.', false),
            array('192.168.1.1', false),
            array('test@42!@$5_', false),
            array('12', false),
            array('bucket_name', false),
            array('bucket-name', true),
            array('bucket', true),
            array('my.bucket.com', true)
        );
    }

    /**
     * @covers Guzzle\Service\Aws\S3\S3Client::isValidBucketName
     * @dataProvider bucketNameProvider
     */
    public function testIsValidBucketName($bucketName, $isValid)
    {
        $this->assertEquals($isValid, s3Client::isValidBucketName($bucketName));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\S3Client::setForcePathHostingBuckets
     * @covers Guzzle\Service\Aws\S3\S3Client::isPathHostingBuckets
     * @covers Guzzle\Service\Aws\S3\S3Client::getS3Request
     * @covers Guzzle\Service\Aws\S3\S3Client::getRequest
     */
    public function testAllowsPathHostingForOldBuckets()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');
        /* @var $client Guzzle\Service\Aws\S3\S3Client */
        $this->assertFalse($client->isPathHostingBuckets());
        $this->assertSame($client, $client->setForcePathHostingBuckets(true));
        $this->assertTrue($client->isPathHostingBuckets());

        // Test using path hosting for older buckets
        $request = $client->getS3Request('GET', 'test', 'key');
        $this->assertEquals('http://s3.amazonaws.com/test/key', $request->getUrl());
        $this->assertEquals('s3.amazonaws.com', $request->getHost());

        // Test using bucket subdomain hosting
        $this->assertSame($client, $client->setForcePathHostingBuckets(false));
        $request = $client->getS3Request('GET', 'test', 'key');
        $this->assertEquals('http://test.s3.amazonaws.com/key', $request->getUrl());
        $this->assertEquals('test.s3.amazonaws.com', $request->getHost());
    }

    /**
     * @covers Guzzle\Service\Aws\S3\S3Client::getS3Request
     */
    public function testGetS3Request()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');
        /* @var $client Guzzle\Service\Aws\S3\S3Client */
        $request = $client->getS3Request('GET');
        $this->assertEquals('http://s3.amazonaws.com/', $request->getUrl());

        $request = $client->getS3Request('GET', 'test');
        $this->assertEquals('http://test.s3.amazonaws.com/', $request->getUrl());

        $request = $client->getS3Request('GET', 'test', 'key');
        $this->assertEquals('http://test.s3.amazonaws.com/key', $request->getUrl());
    }

    /**
     * @covers Guzzle\Service\Aws\S3\S3Client::getSignedUrl
     * @expectedException InvalidArgumentException
     */
    public function testGetSignedUrlThrowsExceptionWhenRequesterPaysAndTorrent()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');
        /* @var $client Guzzle\Service\Aws\S3\S3Client */
        $url = $client->getSignedUrl('test', 'key', 60, 'static.test.com', true, true);
        echo $url;
    }

    /**
     * @covers Guzzle\Service\Aws\S3\S3Client::getSignedUrl
     */
    public function testGetSignedUrl()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');
        /* @var $client Guzzle\Service\Aws\S3\S3Client */
        $url = $client->getSignedUrl('test', 'test.zip', 60, false, false, false);
        $this->assertContains('&Expires=', $url);
        $this->assertContains('&Signature=', $url);
        $this->assertContains('http://test.s3.amazonaws.com/test.zip?AWSAccessKeyId=', $url);

        $url = $client->getSignedUrl('images.test.com', 'test.zip', 60, true, false, false);
        $this->assertContains('http://images.test.com/test.zip?AWSAccessKeyId=', $url);

        $url = $client->getSignedUrl('images.test.com', 'test.zip', 60, false, true, false);
        $this->assertContains('&torrent&', $url);

        $url = $client->getSignedUrl('images.test.com', 'test.zip', 60, false, false, true);
        $this->assertContains('&x-amz-request-payer=requester&', $url);
    }
}