<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class S3SignatureTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function dataProvider()
    {
        return array(
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/db-backup.dat.gz',
                    'headers' => array(
                        'User-Agent' => 'curl/7.15.5',
                        'Host' => 'static.johnsmith.net:8080',
                        'Date' => 'Tue, 27 Mar 2007 21:06:08 +0000',
                        'x-amz-acl' => 'public-read',
                        'content-type' => 'application/x-download',
                        'Content-MD5' => '4gJE4saaMU4BqNR0kLY+lw==',
                        'X-Amz-Meta-ReviewedBy' => 'joe@johnsmith.net,jane@johnsmith.net',
                        'X-Amz-Meta-FileChecksum' => '0x02661779',
                        'X-Amz-Meta-ChecksumAlgorithm' => 'crc32',
                        'Content-Disposition' => 'attachment; filename=database.dat',
                        'Content-Encoding' => 'gzip',
                        'Content-Length' => '5913339'
                    )
                ), "PUT\n4gJE4saaMU4BqNR0kLY+lw==\napplication/x-download\nTue, 27 Mar 2007 21:06:08 +0000\nx-amz-acl:public-read\nx-amz-meta-checksumalgorithm:crc32\nx-amz-meta-filechecksum:0x02661779\nx-amz-meta-reviewedby:joe@johnsmith.net,jane@johnsmith.net\n/static.johnsmith.net/db-backup.dat.gz"
            ),
            // Use two subresources to set the ACL of a specific version and
            // make sure subresources are sorted correctly
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/key?versionId=1234&acl',
                    'headers' => array(
                        'Host' => 'test.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 21:06:08 +0000',
                        'Content-Length' => '15'
                    )
                ),
                "PUT\n\n\nTue, 27 Mar 2007 21:06:08 +0000\n/test/key?acl&versionId=1234"
            ),
            // DELETE a path hosted object with a folder prefix and custom headers
            array(
                array(
                    'verb' => 'DELETE',
                    'path' => '/johnsmith/photos/puppy.jpg',
                    'headers' => array(
                        'User-Agent' => 'dotnet',
                        'Host' => 's3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 21:20:27 +0000',
                        'x-amz-date' => 'Tue, 27 Mar 2007 21:20:26 +0000'
                    )
                ), "DELETE\n\n\n\nx-amz-date:Tue, 27 Mar 2007 21:20:26 +0000\n/johnsmith/photos/puppy.jpg"
            ),
            // List buckets
            array(
                array(
                    'verb' => 'GET',
                    'path' => '/',
                    'headers' => array(
                        'Host' => 's3.amazonaws.com',
                        'Date' => 'Wed, 28 Mar 2007 01:29:59 +0000'
                    )
                ), "GET\n\n\nWed, 28 Mar 2007 01:29:59 +0000\n/"
            ),
            // GET a file from a path hosted bucket with unicode characters
            array(
                array(
                    'verb' => 'GET',
                    'path' => '/dictionary/fran%C3%A7ais/pr%c3%a9f%c3%a8re',
                    'headers' => array(
                        'Host' => 's3.amazonaws.com',
                        'Date' => 'Wed, 28 Mar 2007 01:49:49 +0000'
                    )
                ), "GET\n\n\nWed, 28 Mar 2007 01:49:49 +0000\n/dictionary/fran%C3%A7ais/pr%c3%a9f%c3%a8re"
            ),
            // GET the ACL of a virtual hosted bucket
            array(
                array(
                    'verb' => 'GET',
                    'path' => '/?acl',
                    'headers' => array(
                        'Host' => 'johnsmith.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:44:46 +0000'
                    )
                ), "GET\n\n\nTue, 27 Mar 2007 19:44:46 +0000\n/johnsmith/?acl"
            ),
            // GET the contents of a bucket using parameters
            array(
                array(
                    'verb' => 'GET',
                    'path' => '/?prefix=photos&max-keys=50&marker=puppy',
                    'headers' => array(
                        'User-Agent' => 'Mozilla/5.0',
                        'Host' => 'johnsmith.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:42:41 +0000'
                    )
                ), "GET\n\n\nTue, 27 Mar 2007 19:42:41 +0000\n/johnsmith/"
            ),
            // PUT an object with a folder prefix from a virtual hosted bucket
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/photos/puppy.jpg',
                    'headers' => array(
                        'Content-Type' => 'image/jpeg',
                        'Content-Length' => '94328',
                        'Host' => 'johnsmith.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 21:15:45 +0000'
                    )
                ), "PUT\n\nimage/jpeg\nTue, 27 Mar 2007 21:15:45 +0000\n/johnsmith/photos/puppy.jpg"
            ),
            // GET an object with a folder prefix from a virtual hosted bucket
            array(
                array(
                    'verb' => 'GET',
                    'path' => '/photos/puppy.jpg',
                    'headers' => array(
                        'Host' => 'johnsmith.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:36:42 +0000'
                    )
                ), "GET\n\n\nTue, 27 Mar 2007 19:36:42 +0000\n/johnsmith/photos/puppy.jpg"
            ),
            // Set the ACL of an object
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/photos/puppy.jpg?acl',
                    'headers' => array(
                        'Host' => 'johnsmith.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:36:42 +0000'
                    )
                ), "PUT\n\n\nTue, 27 Mar 2007 19:36:42 +0000\n/johnsmith/photos/puppy.jpg?acl"
            ),
            // Set the ACL of an object with no prefix
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/photos/puppy?acl',
                    'headers' => array(
                        'Host' => 'johnsmith.s3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:36:42 +0000'
                    )
                ), "PUT\n\n\nTue, 27 Mar 2007 19:36:42 +0000\n/johnsmith/photos/puppy?acl"
            ),
            // Set the ACL of an object with no prefix in a path hosted bucket
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/johnsmith/photos/puppy?acl',
                    'headers' => array(
                        'Host' => 's3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:36:42 +0000'
                    )
                ), "PUT\n\n\nTue, 27 Mar 2007 19:36:42 +0000\n/johnsmith/photos/puppy?acl"
            ),
            // Set the ACL of a path hosted bucket
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/johnsmith/?acl',
                    'headers' => array(
                        'Host' => 's3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:36:42 +0000'
                    )
                ), "PUT\n\n\nTue, 27 Mar 2007 19:36:42 +0000\n/johnsmith/?acl"
            ),
            // Set the ACL of a path hosted bucket with an erroneous path value
            array(
                array(
                    'verb' => 'PUT',
                    'path' => '/johnsmith?acl',
                    'headers' => array(
                        'Host' => 's3.amazonaws.com',
                        'Date' => 'Tue, 27 Mar 2007 19:36:42 +0000'
                    ),
                ), "PUT\n\n\nTue, 27 Mar 2007 19:36:42 +0000\n/johnsmith/?acl"
            )
        );
    }

    /**
     * @covers Guzzle\Service\Aws\S3\S3Signature
     * @dataProvider dataProvider
     */
    public function testCreateCanonicalizedString($input, $result)
    {
        $signature = new \Guzzle\Service\Aws\S3\S3Signature('a', 's');
        $this->assertEquals($result, $signature->createCanonicalizedString($input['headers'], $input['path'], $input['verb']));
    }
}