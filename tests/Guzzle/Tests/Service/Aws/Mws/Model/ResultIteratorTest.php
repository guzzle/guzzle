<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Model;

use Guzzle\Service\Aws\Mws\Model\ResultIterator;
use Guzzle\Tests\GuzzleTestCase;

class ResultIteratorTest extends GuzzleTestCase
{
    public function test__construct()
    {
        // Try to iterate over a non-iterable command, should throw an exception
        $client = $this->getServiceBuilder()->getClient('test.mws');
        $command = $client->getCommand('get_report');
        $this->setExpectedException('InvalidArgumentException');
        $iterator = new ResultIterator($client, $command);
    }

    public function testResultIterator()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');
        $this->setMockResponse($client, 'GetReportListResponse');

        $command = $client->getCommand('get_report_list');
        $iterator = new ResultIterator($client, $command);

        foreach($iterator as $key => $row) {
            $this->setMockResponse($client, 'GetReportListByNextTokenResponse');
            $this->assertInstanceOf('\SimpleXMLElement', $row);
            $this->assertStringMatchesFormat('%d_%d', $iterator->key());
        }
        
    }
}