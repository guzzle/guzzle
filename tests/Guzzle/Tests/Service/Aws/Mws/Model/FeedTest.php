<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */
namespace Guzzle\Tests\Service\Aws\Mws;

use Guzzle\Common\XmlElement;
use Guzzle\Service\Aws\Mws\Model\Feed;
use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Service\Aws\Mws\Model\Feed
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class FeedTest extends GuzzleTestCase
{
    public function testFeed()
    {
        $feed = new Feed();

        $feed->setMessage('<Sample />');

        $this->assertInstanceOf('Guzzle\Common\XmlElement', $feed->getMessage());
        $this->assertInstanceOf('Guzzle\Common\XmlElement', $feed->getXml());
        $this->assertContains('<?xml', $feed->toXml());
        $this->assertContains('<?xml', (string)$feed);
    }
}