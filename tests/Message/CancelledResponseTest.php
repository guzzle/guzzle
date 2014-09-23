<?php
namespace GuzzleHttp\Tests\Command;

use GuzzleHttp\Message\CancelledResponse;

class CancelledResponseTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \GuzzleHttp\Exception\StateException
     */
    public function testThrowsWhenAccessed()
    {
        (new CancelledResponse())->getStatusCode();
    }
}
