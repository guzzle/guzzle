<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Object;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DeleteObjectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\DeleteObject
     */
    public function testDeleteObject()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Object\DeleteObject();
        $command->setBucket('test')->setKey('key');
        $command->setMfa('testing');
        $command->setVersionId('123');
        
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'DeleteObjectResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/key?versionId=123', $command->getRequest()->getUrl());
        $this->assertEquals('DELETE', $command->getRequest()->getMethod());

        $this->assertEquals('testing', $command->getRequest()->getHeader('x-amz-mfa'));
    }
}