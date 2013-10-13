<?php

namespace Guzzle\Tests\Http\Adapter;

require_once __DIR__ . '/../Server.php';

use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Tests\Http\Server;

/**
 * @covers Guzzle\Http\Adapter\StreamAdapter
 */
class StreamAdapterTest extends \PHPUnit_Framework_TestCase
{
    /** @var \Guzzle\Tests\Http\Server */
    static $server;

    public static function setUpBeforeClass()
    {
        self::$server = new Server();
        self::$server->start();
    }

    public static function tearDownAfterClass()
    {
        self::$server->stop();
    }

    public function testReturnsResponseForSuccessfulRequest()
    {

    }

    public function testThrowsExceptionsCaughtDuringTransfer()
    {

    }

    public function testCanHandleExceptionsUsingEvents()
    {

    }

    public function testStreamAttributeKeepsStreamOpen()
    {

    }

    public function testDrainsResponseIntoTempStream()
    {

    }

    public function testDrainsResponseIntoSaveToBody()
    {

    }

    public function testCreatesResponseFromHttpStreamWrapper()
    {

    }

    public function testThrowsExceptionsForStreamErrors()
    {

    }

    public function testAddsGzipFilterIfAcceptHeaderIsPresent()
    {

    }

    public function testCreatesFopenResource()
    {

    }

    public function testCreatesFopenResourceAndCatchesPhpErrorsWithException()
    {

    }

    public function testAddsProxy()
    {

    }

    public function testAddsTimeout()
    {

    }

    public function testVerifiesVerifyIsValidIfPath()
    {

    }

    public function testVerifyCanBeDisabled()
    {

    }

    public function testVerifyCanBeSetToPath()
    {

    }

    public function testVerifiesCertIfValidPath()
    {

    }

    public function testCanSetPasswordWhenSettingCert()
    {

    }

    public function testDebugAttributeWritesStreamInfoToTempBufferByDefault()
    {

    }

    public function testDebugAttributeWritesStreamInfoToBuffer()
    {

    }
}
