<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Command;

use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\Client;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\ApiCommand;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ConcreteCommandFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ServiceDescription
     */
    protected $service;

    public function setUp()
    {
        $this->service = new ServiceDescription('test', 'Test service', 'http://www.test.com/', array(
            new ApiCommand(array(
                'name' => 'test_command',
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'can_batch' => true,
                'concrete_command_class' => 'Guzzle\\Service\\Aws\\S3\\Command\\Object\\DeleteObject',
                'args' => array(
                    'bucket' => array(
                        'required' => true
                    ),
                    'key' => array(
                        'required' => true
                    )
                )
            ))
        ));
    }

    /**
     * @covers Guzzle\Service\Command\ConcreteCommandFactory
     * @covers Guzzle\Service\Command\AbstractCommandFactory
     */
    public function testConstructor()
    {
        $factory = new ConcreteCommandFactory($this->service);
    }

    /**
     * @covers Guzzle\Service\Command\AbstractCommandFactory
     * @expectedException InvalidArgumentException
     */
    public function testEnsuresTheCommandExists()
    {
        $factory = new ConcreteCommandFactory($this->service);
        $factory->buildCommand('aaaa', array());
    }

    /**
     * @covers Guzzle\Service\Command\ConcreteCommandFactory
     * @covers Guzzle\Service\Command\AbstractCommandFactory
     */
    public function testCreatesConcreteCommands()
    {
        $factory = new ConcreteCommandFactory($this->service);
        $command = $factory->buildCommand('test_command', array(
            'bucket' => 'test',
            'key' => 'my_key.txt'
        ));

        $this->assertInstanceOf('Guzzle\\Service\\Aws\\S3\\Command\\Object\\DeleteObject', $command);
        $this->assertEquals('test', $command->get('bucket'));
        $this->assertEquals('my_key.txt', $command->get('key'));
    }
}