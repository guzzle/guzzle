<?php

namespace Guzzle\Tests\Service\Mock\Command;

/**
 * Other mock Command
 */
class OtherCommand extends MockCommand
{
    public static function getApi()
    {
        return array(
            'name' => 'other_command',
            'params' => array(
                'test' => array(
                    'default'  => '123',
                    'required' => true,
                    'doc'      => 'Test argument'
                ),
                'other'  => array(),
                'arg'    => array('type' => 'string'),
                'static' => array(
                    'static' => 'this is static'
                )
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('HEAD');
    }
}
