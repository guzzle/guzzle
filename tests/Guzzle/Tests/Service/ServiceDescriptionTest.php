<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service;

use Guzzle\Common\Collection;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ServiceDescriptionTest extends \Guzzle\Tests\GuzzleTestCase
{
     /**
     * @covers Guzzle\Service\ServiceDescription
      * @covers Guzzle\Service\ServiceDescription::__construct
      * @covers Guzzle\Service\ServiceDescription::getName
      * @covers Guzzle\Service\ServiceDescription::getDescription
      * @covers Guzzle\Service\ServiceDescription::getBaseUrl
      * @covers Guzzle\Service\ServiceDescription::getCommands
      * @covers Guzzle\Service\ServiceDescription::getCommand
     */
    public function testConstructor()
    {
        $service = new ServiceDescription('test', 'description', 'base_url', array());
        $this->assertEquals('test', $service->getName());
        $this->assertEquals('description', $service->getDescription());
        $this->assertEquals('base_url', $service->getBaseUrl());
        $this->assertEquals(array(), $service->getCommands());
        $this->assertFalse($service->hasCommand('test'));
    }
}