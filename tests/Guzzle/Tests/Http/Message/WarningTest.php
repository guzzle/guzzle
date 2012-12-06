<?php

namespace Guzzle\Tests\Http\Message;

use DateTime;
use Guzzle\Http\Message\Warning;

/**
 * @covers Guzzle\Http\Message\Warning
 */
class WarningTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testConstructs()
    {
        $date = new DateTime();
        $warning = new Warning('110', 'test', 'Response is stale', $date);

        $this->assertEquals(110, $warning->getCode());
        $this->assertEquals('test', $warning->getAgent());
        $this->assertEquals('Response is stale', $warning->getText());
        $this->assertEquals($date->format('r'), $warning->getDate()->format('r'));
        $this->assertEquals(sprintf('110 test "Response is stale" "%s"', $date->format('D, d M Y H:i:s e')), (string) $warning);

        $warning = new Warning('111', 'test');

        $this->assertEquals(111, $warning->getCode());
        $this->assertEquals('test', $warning->getAgent());
        $this->assertEquals('Revalidation failed', $warning->getText());
        $this->assertNull($warning->getDate());
        $this->assertEquals('111 test "Revalidation failed"', (string) $warning);
    }

    public function testFromHeader()
    {
        $header = '110 test "Response is stale" "Mon, 03 Dec 2012 09:15:53 GMT"';
        $warning = Warning::fromHeader($header);
        $this->assertEquals(110, $warning->getCode());
        $this->assertEquals('test', $warning->getAgent());
        $this->assertEquals('Response is stale', $warning->getText());
        $this->assertEquals('Mon, 03 Dec 2012 09:15:53 GMT', $warning->getDate()->format('D, d M Y H:i:s e'));
        $this->assertEquals($header, (string) $warning);

        $header = '111 test "Revalidation failed"';
        $warning = Warning::fromHeader($header);
        $this->assertEquals(111, $warning->getCode());
        $this->assertEquals('test', $warning->getAgent());
        $this->assertEquals('Revalidation failed', $warning->getText());
        $this->assertNull($warning->getDate());
        $this->assertEquals($header, (string) $warning);
    }
}
