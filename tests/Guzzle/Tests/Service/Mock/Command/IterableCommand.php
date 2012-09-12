<?php

namespace Guzzle\Tests\Service\Mock\Command;

use Guzzle\Service\Description\ApiCommand;

/**
 * Iterable mock command
 */
class IterableCommand extends MockCommand
{
    public static function getApi()
    {
        return array(
            'name'   => 'iterable_command',
            'params' => array(
                'page_size' => array('type' => 'integer'),
                'next_token' => array('type' => 'string')
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->createRequest('GET');

        // Add the next token and page size query string values
        $this->request->getQuery()->set('next_token', $this->get('next_token'));

        if ($this->get('page_size')) {
            $this->request->getQuery()->set('page_size', $this->get('page_size'));
        }
    }
}
