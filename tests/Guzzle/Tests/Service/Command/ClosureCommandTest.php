<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Command;

use Guzzle\Http\Message\RequestFactory;
use Guzzle\Service\Command\ClosureCommand;
use Guzzle\Service\Client;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClosureCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Command\ClosureCommand
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage A closure must be passed in the parameters array
     */
    public function testConstructorValidatesClosure()
    {
        $c = new ClosureCommand();
    }

    /**
     * @covers Guzzle\Service\Command\ClosureCommand
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage A closure_api value must be passed in the parameters array
     */
    public function testConstructorValidatesClosureApi()
    {
        $c = new ClosureCommand(array(
            'closure' => function() {}
        ));
    }

    /**
     * @covers Guzzle\Service\Command\ClosureCommand
     */
    public function testCanSetCanBatch()
    {
        $c = new ClosureCommand(array(
            'closure' => function() {},
            'closure_api' => true
        ));

        $this->assertTrue($c->canBatch());
        $this->assertSame($c, $c->setCanBatch(false));
        $this->assertFalse($c->canBatch());
    }

    /**
     * @covers Guzzle\Service\Command\ClosureCommand::prepare
     */
    public function testExecutesClosure()
    {
        $c = new ClosureCommand(array(
            'closure' => function($command, $api) {
                $command->set('testing', '123');
                $request = RequestFactory::get('http://www.test.com/');
                return $request;
            },
            'closure_api' => true
        ));

        $client = $this->getServiceBuilder()->get('mock');
        $c->prepare($client);
        $this->assertEquals('123', $c->get('testing'));
        $this->assertEquals('http://www.test.com/', $c->getRequest()->getUrl());
    }

    /**
     * @covers Guzzle\Service\Command\ClosureCommand
     * @expectedException Guzzle\Service\ServiceException
     * @expectedExceptionMessage Closure command did not return a RequestInterface object
     */
    public function testMustReturnRequest()
    {
        $c = new ClosureCommand(array(
            'closure' => function($command, $api) {
                return false;
            },
            'closure_api' => true
        ));

        $client = $this->getServiceBuilder()->get('mock');
        $c->prepare($client);
    }
}