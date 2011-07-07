<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Common\Collection;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\XmlDescriptionBuilder;
use Guzzle\Service\Description\ApiCommand;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ServiceDescriptionTest extends \Guzzle\Tests\GuzzleTestCase
{
     /**
      * @covers Guzzle\Service\Description\ServiceDescription
      * @covers Guzzle\Service\Description\ServiceDescription::__construct
      * @covers Guzzle\Service\Description\ServiceDescription::getCommands
      * @covers Guzzle\Service\Description\ServiceDescription::getCommand
      */
    public function testConstructor()
    {
        $service = new ServiceDescription(array(
            new ApiCommand(array(
                'name' => 'test_command',
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'can_batch' => true,
                'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
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

        $this->assertEquals(1, count($service->getCommands()));
        $this->assertFalse($service->hasCommand('foobar'));
        $this->assertTrue($service->hasCommand('test_command'));

        $c = $service->createCommand('test_command', array(
            'bucket' => '123',
            'key' => 'abc'
        ));

        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand', $c);
        $this->assertEquals('123', $c->get('bucket'));
        $this->assertEquals('abc', $c->get('key'));

        try {
            $service->createCommand('foobar', array());
            $this->fail('Expected exception not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals('foobar command not found', $e->getMessage());
        }
    }
}