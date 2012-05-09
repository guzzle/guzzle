<?php

namespace Guzzle\Tests\Service\Mock\Model;

use Guzzle\Service\Resource\ResourceIterator;

class MockCommandIterator extends ResourceIterator
{
    protected function sendRequest()
    {
        if ($this->nextToken) {
            $this->command->set('next_token', $this->nextToken);
        }

        $this->command->set('page_size', (int) $this->calculatePageSize());
        $this->command->execute();

        $data = json_decode($this->command->getResponse()->getBody(true), true);

        $this->nextToken = $data['next_token'];

        return $data['resources'];
    }
}
