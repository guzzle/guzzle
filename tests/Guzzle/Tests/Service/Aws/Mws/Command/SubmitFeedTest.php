<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\SubmitFeed
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class SubmitFeedTest extends GuzzleTestCase
{
    public function testSubmitFeed()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');
        $this->setMockResponse($client, 'SubmitFeedResponse');

        $command = $client->getCommand('submit_feed')
            ->setFeedContent('asdf')
            ->setFeedType(Type\FeedType::PRODUCT_FEED)
            ->setPurgeAndReplace(true);
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\SubmitFeed', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('SubmitFeed', $qs->get('Action'));
        $this->assertEquals('_POST_PRODUCT_DATA_', $qs->get('FeedType'));
        $this->assertEquals('true', $qs->get('PurgeAndReplace'));
    }
}