<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\CancelFeedSubmissions
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class CancelFeedSubmissionsTest extends GuzzleTestCase
{
    public function testCancelFeedSubmissions()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'CancelFeedSubmissionsResponse');

        $command = $client->getCommand('cancel_feed_submissions')
            ->setFeedSubmissionIdList(array(
                12345
            ))
            ->setFeedTypeList(array(
                Type\FeedType::PRODUCT_FEED
            ))
            ->setSubmittedFromDate(new \DateTime('2011-01-01'))
            ->setSubmittedToDate(new \DateTime('2011-01-10'));
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\CancelFeedSubmissions', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('CancelFeedSubmissions', $qs->get('Action'));
        $this->assertEquals('12345', $qs->get('FeedSubmissionIdList.Id.1'));
        $this->assertEquals('_POST_PRODUCT_DATA_', $qs->get('FeedTypeList.Type.1'));
        $this->assertArrayHasKey('SubmittedFromDate', $qs->getAll());
        $this->assertArrayHasKey('SubmittedToDate', $qs->getAll());
    }
}