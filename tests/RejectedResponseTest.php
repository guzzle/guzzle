<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\RejectedResponse;
use GuzzleHttp\Psr7\Stream;

class RejectedResponseTest extends \PHPUnit_Framework_TestCase
{
    public function testActsAsResponse()
    {
        $p = new RejectedResponse(new \UnexpectedValueException('test'));
        $this->assertEquals('rejected', $p->getState());
        /** @var callable $f */
        $f = [$this, 'check'];
        $f($p, 'getStatusCode');
        $f($p, 'getReasonPhrase');
        $f($p, 'getHeaders');
        $f($p, 'getHeaderLines', ['foo']);
        $f($p, 'hasHeader', ['foo']);
        $f($p, 'getHeader', ['foo']);
        $f($p, 'withAddedHeader', ['foo', 'bar']);
        $f($p, 'withHeader', ['foo', 'bar']);
        $f($p, 'withoutHeader', ['foo']);
        $f($p, 'getBody');
        $f($p, 'withBody', [Stream::factory('test')]);
        $f($p, 'getProtocolVersion');
        $f($p, 'withProtocolVersion', ['2']);
        $f($p, 'withStatus', ['202']);
    }

    private function check($m, $f, array $args = [])
    {
        try {
            call_user_func_array([$m, $f], $args);
            $this->fail('Should have thrown');
        } catch (\UnexpectedValueException $e) {
            $this->assertEquals('test', $e->getMessage());
        }
    }
}
