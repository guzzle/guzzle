<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\RequestReport
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class RequestReportTest extends GuzzleTestCase
{
    public function testRequestReport()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'RequestReportResponse');
        
        $command = $client->getCommand('request_report')
            ->setReportType(Type\ReportType::MERCHANT_LISTINGS_REPORT)
            ->setStartDate(new \DateTime('2011-01-01'))
            ->setEndDate(new \DateTime());

        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\RequestReport', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('RequestReport', $qs->get('Action'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportType'));
        $this->assertArrayHasKey('StartDate', $qs->getAll());
        $this->assertArrayHasKey('EndDate', $qs->getAll());
    }
}