<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Common\Collection;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Description\ServiceDescription;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ApiCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Description\ApiCommand
     */
    public function testApiCommandIsDataObject()
    {
        $c = new ApiCommand(array(
            'name' => 'test',
            'doc' => 'doc',
            'method' => 'POST',
            'path' => '/api/v1',
            'min_args' => 2,
            'can_batch' => true,
            'args' => array(
                'key' => array(
                    'required' => 'true',
                    'type' => 'string',
                    'max_length' => 10
                ),
                'key_2' => array(
                    'required' => 'true',
                    'type' => 'integer',
                    'default' => 10
                )
           )
        ));

        $this->assertEquals('test', $c->getName());
        $this->assertEquals('doc', $c->getDoc());
        $this->assertEquals('POST', $c->getMethod());
        $this->assertEquals('/api/v1', $c->getPath());
        $this->assertEquals('Guzzle\\Service\\Command\\ClosureCommand', $c->getConcreteClass());
        $this->assertEquals(2, $c->getMinArgs());
        $this->assertEquals(array(
            'key' => new Collection(array(
                'required' => 'true',
                'type' => 'string',
                'max_length' => 10
            )),
            'key_2' => new Collection(array(
                'required' => 'true',
                'type' => 'integer',
                'default' => 10
            ))
        ), $c->getArgs());

        $this->assertEquals(new Collection(array(
            'required' => 'true',
            'type' => 'integer',
            'default' => 10
        )), $c->getArg('key_2'));

        $this->assertTrue($c->canBatch());

        $this->assertEquals(array(
            'test requires at least 2 arguments',
            'Requires that the key argument be supplied.'
        ), $c->validate(new Collection(array())));

        $this->assertNull($c->getArg('afefwef'));
    }

    /**
     * @covers Guzzle\Service\Description\ApiCommand::__construct
     */
    public function testAllowsConcreteCommands()
    {
        $c = new ApiCommand(array(
            'name' => 'test',
            'class' => 'Guzzle\\Service\\Command\ClosureCommand',
            'args' => array(
                'p' => new Collection(array(
                    'name' => 'foo'
                ))
            )
        ));
        $this->assertEquals('Guzzle\\Service\\Command\ClosureCommand', $c->getConcreteClass());
    }
}