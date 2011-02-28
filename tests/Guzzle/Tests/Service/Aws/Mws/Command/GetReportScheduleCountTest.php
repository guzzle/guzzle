<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetReportScheduleCount
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportScheduleCountTest extends GuzzleTestCase
{
    public function testGetReportScheduleCountTest()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetReportScheduleCountResponse');

        $command = $client->getCommand('get_report_schedule_count')
            ->setReportTypeList(array(
                Type\ReportType::MERCHANT_LISTINGS_REPORT
            ));
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReportScheduleCount', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);
        
        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReportScheduleCount', $qs->get('Action'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportTypeList.Type.1'));
    }
}