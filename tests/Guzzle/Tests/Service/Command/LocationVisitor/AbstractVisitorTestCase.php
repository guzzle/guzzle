<?php

namespace Guzzle\Tests\Service\Command\LocationVisitor;

use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Tests\Service\Mock\Command\MockCommand;

abstract class AbstractVisitorTestCase extends \Guzzle\Tests\GuzzleTestCase
{
    protected $command;
    protected $request;

    public function setUp()
    {
        $this->command = new MockCommand();
        $this->request = new EntityEnclosingRequest('POST', 'http://www.test.com');
    }
}
