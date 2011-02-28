<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetFeedSubmissionCount
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetFeedSubmissionCountTest extends GuzzleTestCase
{
    public function testGetFeedSubmissionCount()
    {
        // Get client
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetFeedSubmissionCountResponse');

        $command = $client->getCommand('get_feed_submission_count')
            ->setFeedTypeList(array(
                Type\FeedType::PRODUCT_FEED
            ))
            ->setFeedProcessingStatusList(array(
                Type\FeedProcessingStatus::DONE
            ))
            ->setSubmittedFromDate(new \DateTime('2011-01-01'))
            ->setSubmittedToDate(new \DateTime());
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetFeedSubmissionCount', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetFeedSubmissionCount', $qs->get('Action'));
        $this->assertEquals('_POST_PRODUCT_DATA_', $qs->get('FeedTypeList.Type.1'));
        $this->assertEquals('_DONE_', $qs->get('FeedProcessingStatusList.Status.1'));
        $this->assertArrayHasKey('SubmittedFromDate', $qs->getAll());
        $this->assertArrayHasKey('SubmittedToDate', $qs->getAll());
    }
}
