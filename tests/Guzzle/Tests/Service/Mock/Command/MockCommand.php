<?php

namespace Guzzle\Tests\Service\Mock\Command;

/**
 * Mock Command
 */
class MockCommand extends \Guzzle\Service\Command\AbstractCommand
{
    public static function getApi()
    {
        return array(
            'name' => get_called_class() == __CLASS__ ? 'mock_command' : 'sub.sub',
            'params' => array(
                'test' => array(
                    'default'  => 123,
                    'required' => true,
                    'doc'      => 'Test argument'
                ),
                '_internal' => array(
                    'default' => 'abc'
                )
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->createRequest();
    }
}
