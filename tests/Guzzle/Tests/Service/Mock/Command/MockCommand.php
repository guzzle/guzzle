<?php

namespace Guzzle\Tests\Service\Mock\Command;

use Guzzle\Service\Description\Operation;

class MockCommand extends \Guzzle\Service\Command\AbstractCommand
{
    protected function createOperation()
    {
        return new Operation(array(
            'name'       => get_called_class() == __CLASS__ ? 'mock_command' : 'sub.sub',
            'httpMethod' => 'POST',
            'parameters' => array(
                'test' => array(
                    'default'  => 123,
                    'required' => true,
                    'doc'      => 'Test argument'
                ),
                '_internal' => array(
                    'default' => 'abc'
                )
            )
        ));
    }

    protected function build()
    {
        $this->request = $this->client->createRequest();
    }
}
