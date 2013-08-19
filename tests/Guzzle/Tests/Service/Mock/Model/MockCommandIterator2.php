<?php

namespace Guzzle\Tests\Service\Mock\Model;

use Guzzle\Service\Resource\ResourceIterator;

class MockCommandIterator2 extends ResourceIterator
{
    public $calledNext = 0;

    protected function sendRequest()
    {
        $this->command->set('page_size', (int)$this->calculatePageSize());
        $this->command->execute();

        $data = json_decode($this->command->getResponse()->getBody(true), true);

        // Go to the next page
        if (is_array($data) && count($data) >= $this->command->get('page_size')) {
            $this->nextToken = 1 + ($this->nextToken ? : 1);
        } else {
            $this->nextToken = false; // this was the last page
        }

        return $data;
    }

    public function next()
    {
        $this->calledNext++;
        parent::next();
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function getIteratedCount()
    {
        return $this->iteratedCount;
    }
}
