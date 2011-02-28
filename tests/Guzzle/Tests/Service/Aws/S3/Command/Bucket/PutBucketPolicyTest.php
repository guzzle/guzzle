<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\Bucket\PutBucketPolicy;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketPolicyTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketPolicy
     */
    public function testPutBucketPolicy()
    {
        $policy = array(
            'Version' => '2008-10-17',
            'Id' => 'aaaa-bbbb-cccc-dddd',
            'Statement' => array(
                0 => array(
                    'Effect' => 'Deny',
                    'Sid' => '1',
                    'Principal' => array(
                        'AWS' => array('1-22-333-4444', '3-55-678-9100'),
                    ),
                    'Action' => array('s3:*',),
                    'Resource' => 'arn:aws:s3:::bucket/*',
                )
            )
        );

        $encodedPolicy = json_encode($policy);
        
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketPolicy();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setPolicy($policy));
        
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketPolicyResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertContains('PUT /?policy HTTP/1.1', $request);
        $this->assertContains('Host: test.s3.amazonaws.com', $request);
        $this->assertContains($encodedPolicy, $request);
    }
}