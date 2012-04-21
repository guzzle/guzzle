<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Command\Factory\ServiceDescriptionFactory;

class ServiceDescriptionFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testProvider()
    {
        return array(
            array('foo', null),
            array('jarjar', 'Guzzle\Tests\Service\Mock\Command\MockCommand'),
            array('binks', 'Guzzle\Tests\Service\Mock\Command\OtherCommand')
        );
    }

    /**
     * @covers Guzzle\Service\Command\Factory\ServiceDescriptionFactory
     * @dataProvider testProvider
     */
    public function testCreatesCommandsUsingServiceDescriptions($key, $result)
    {
        $d = ServiceDescription::factory(array(
            'commands' => array(
                'jarjar' => array(
                    'class' => 'Guzzle\Tests\Service\Mock\Command\MockCommand'
                ),
                'binks' => array(
                    'class' => 'Guzzle\Tests\Service\Mock\Command\OtherCommand'
                )
            )
        ));

        $factory = new ServiceDescriptionFactory($d);
        $this->assertSame($d, $factory->getServiceDescription());

        if (is_null($result)) {
            $this->assertNull($factory->factory($key));
        } else {
            $this->assertInstanceof($result, $factory->factory($key));
        }
    }
}
