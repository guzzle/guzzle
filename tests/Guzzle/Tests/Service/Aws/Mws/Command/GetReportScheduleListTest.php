<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetReportScheduleList
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportScheduleListTest extends GuzzleTestCase
{
    public function testGetReportScheduleList()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetReportScheduleListResponse');

        $command = $client->getCommand('get_report_schedule_list')
            ->setReportTypeList(array(
                Type\ReportType::MERCHANT_LISTINGS_REPORT
            ));

        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReportScheduleList', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);
        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReportScheduleList', $qs->get('Action'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportTypeList.Type.1'));
    }
}