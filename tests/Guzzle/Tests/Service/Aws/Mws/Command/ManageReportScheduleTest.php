<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\ManageReportSchedule
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class ManageReportScheduleTest extends GuzzleTestCase
{
    public function testManageReportSchedule()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'ManageReportScheduleResponse');
        
        $command = $client->getCommand('manage_report_schedule')
            ->setReportType(Type\ReportType::MERCHANT_LISTINGS_REPORT)
            ->setSchedule(Type\Schedule::EVERY_HOUR)
            ->setScheduledDate(new \DateTime());
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\ManageReportSchedule', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);
        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('ManageReportSchedule', $qs->get('Action'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportType'));
        $this->assertEquals('_1_HOUR_', $qs->get('Schedule'));
        $this->assertArrayHasKey('ScheduledDate', $qs->getAll());
    }
}
