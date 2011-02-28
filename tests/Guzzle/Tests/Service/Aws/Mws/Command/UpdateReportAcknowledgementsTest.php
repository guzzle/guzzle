<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\UpdateReportAcknowledgements
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class UpdateReportAcknowledgementsTest extends GuzzleTestCase
{
    public function testUpdateReportAcknowledgements()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'UpdateReportAcknowledgementsResponse');

        $command = $client->getCommand('update_report_acknowledgements')
            ->setReportIdList(array(
                12345
            ))
            ->setAcknowledged(true);
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\UpdateReportAcknowledgements', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('UpdateReportAcknowledgements', $qs->get('Action'));
        $this->assertEquals('12345', $qs->get('ReportIdList.Id.1'));
        $this->assertEquals('true', $qs->get('Acknowledged'));
    }
}