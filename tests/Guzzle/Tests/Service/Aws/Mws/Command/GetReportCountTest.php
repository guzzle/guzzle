<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/*
 * @covers Guzzle\Service\Aws\Mws\Command\GetReportCount
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportCountTest extends GuzzleTestCase
{
    public function testGetReportCount()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetReportCountResult');

        $command = $client->getCommand('get_report_count')
            ->setReportTypeList(array(
                Type\ReportType::MERCHANT_LISTINGS_REPORT
            ))
            ->setAcknowledged(true)
            ->setAvailableFromDate(new \DateTime('2011-01-01'))
            ->setAvailableToDate(new \DateTime());

        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReportCount', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReportCount', $qs->get('Action'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportTypeList.Type.1'));
        $this->assertEquals('true', $qs->get('Acknowledged'));
        $this->assertArrayHasKey('AvailableFromDate', $qs->getAll());
        $this->assertArrayHasKey('AvailableToDate', $qs->getAll());
    }
}