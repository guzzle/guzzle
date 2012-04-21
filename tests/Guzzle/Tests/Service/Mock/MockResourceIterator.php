<?php

namespace Guzzle\Tests\Service\Mock;

use Guzzle\Service\ResourceIterator;

class MockResourceIterator extends ResourceIterator
{
    protected function sendRequest()
    {
        $request = $this->client->createRequest();
        $request->getQuery()->set('count', $this->calculatePageSize());
        $data = json_decode($request->send()->getBody(true), true);

        $this->resourceList = $data['resources'];
        $this->nextToken = $data['next_token'];
        $this->retrievedCount += count($this->data['resources']);
        $this->currentIndex = 0;
    }
}
