<?php
namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7;

/**
 * @covers \GuzzleHttp\Handler\EasyHandle
 */
class EasyHandleTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessage The EasyHandle has been released
     */
    public function testEnsuresHandleExists()
    {
        $easy = new EasyHandle;
        unset($easy->handle);
        $easy->handle;
    }
}
