<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Unfuddle;

use Guzzle\Service\Unfuddle\UnfuddleClient;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder;

/**
 * @group Unfuddle
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class UnfuddleClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Unfuddle\UnfuddleClient::__construct
     */
    public function test__construct()
    {
        $b = new ConcreteDescriptionBuilder('Guzzle\\Service\\Unfuddle\\UnfuddleClient');
        $s = $b->build();
        $f = new ConcreteCommandFactory($s);

        $client = new UnfuddleClient(array(
            'username' => 'abe',
            'password' => 'lincoln',
            'subdomain' => 'president'
        ), $s, $f);

        $this->assertEquals('https://president.unfuddle.com/api/v1/', $client->getBaseUrl());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\UnfuddleClient::getRequest
     */
    public function testConfiguresUnfuddleRequests()
    {
        $b = new ConcreteDescriptionBuilder('Guzzle\\Service\\Unfuddle\\UnfuddleClient');
        $s = $b->build();
        $f = new ConcreteCommandFactory($s);

        $client = new UnfuddleClient(array(
            'username' => 'abe',
            'password' => 'lincoln',
            'subdomain' => 'president'
        ), $s, $f);

        $request = $client->getRequest('GET');
        $this->assertEquals('abe', $request->getUsername());
        $this->assertEquals('lincoln', $request->getPassword());
        $this->assertEquals('', $request->getQuery()->getPrefix());
        $this->assertEquals('/', $request->getQuery()->getFieldSeparator());
        $this->assertEquals('/', $request->getQuery()->getValueSeparator());
    }
}