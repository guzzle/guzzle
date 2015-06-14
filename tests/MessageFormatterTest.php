<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\MessageFormatter;

/**
 * @covers GuzzleHttp\MessageFormatter
 */
class MessageFormatterTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesWithClfByDefault()
    {
        $f = new MessageFormatter();
        $this->assertEquals(MessageFormatter::CLF, $this->readAttribute($f, 'template'));
        $f = new MessageFormatter(null);
        $this->assertEquals(MessageFormatter::CLF, $this->readAttribute($f, 'template'));
    }

    public function dateProvider()
    {
        return [
            ['{ts}', '/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}/'],
            ['{date_iso_8601}', '/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}/'],
            ['{date_common_log}', '/^\d\d\/[A-Z][a-z]{2}\/[0-9]{4}/']
        ];
    }

    /**
     * @dataProvider dateProvider
     */
    public function testFormatsTimestamps($format, $pattern)
    {
        $f = new MessageFormatter($format);
        $request = new Request('GET', '/');
        $result = $f->format($request);
        $this->assertEquals(1, preg_match($pattern, $result));
    }

    public function formatProvider()
    {
        $request = new Request('PUT', '/', ['x-test' => 'abc'], Psr7\stream_for('foo'));
        $response = new Response(200, ['X-Baz' => 'Bar'], Psr7\stream_for('baz'));
        $err = new RequestException('Test', $request, $response);

        return [
            ['{request}', [$request], Psr7\str($request)],
            ['{response}', [$request, $response], Psr7\str($response)],
            ['{request} {response}', [$request, $response], Psr7\str($request) . ' ' . Psr7\str($response)],
            // Empty response yields no value
            ['{request} {response}', [$request], Psr7\str($request) . ' '],
            ['{req_headers}', [$request], "PUT / HTTP/1.1\r\nx-test: abc"],
            ['{res_headers}', [$request, $response], "HTTP/1.1 200 OK\r\nX-Baz: Bar"],
            ['{res_headers}', [$request], 'NULL'],
            ['{req_body}', [$request], 'foo'],
            ['{res_body}', [$request, $response], 'baz'],
            ['{res_body}', [$request], 'NULL'],
            ['{method}', [$request], $request->getMethod()],
            ['{url}', [$request], $request->getUri()],
            ['{target}', [$request], $request->getRequestTarget()],
            ['{req_version}', [$request], $request->getProtocolVersion()],
            ['{res_version}', [$request, $response], $response->getProtocolVersion()],
            ['{res_version}', [$request], 'NULL'],
            ['{host}', [$request], $request->getHeaderLine('Host')],
            ['{hostname}', [$request, $response], gethostname()],
            ['{hostname}{hostname}', [$request, $response], gethostname() . gethostname()],
            ['{code}', [$request, $response], $response->getStatusCode()],
            ['{code}', [$request], 'NULL'],
            ['{phrase}', [$request, $response], $response->getReasonPhrase()],
            ['{phrase}', [$request], 'NULL'],
            ['{error}', [$request, $response, $err], 'Test'],
            ['{error}', [$request], 'NULL'],
            ['{req_header_x-test}', [$request], 'abc'],
            ['{req_header_x-not}', [$request], ''],
            ['{res_header_X-Baz}', [$request, $response], 'Bar'],
            ['{res_header_x-not}', [$request, $response], ''],
            ['{res_header_X-Baz}', [$request], 'NULL'],
        ];
    }

    /**
     * @dataProvider formatProvider
     */
    public function testFormatsMessages($template, $args, $result)
    {
        $f = new MessageFormatter($template);
        $this->assertEquals((string) $result, call_user_func_array(array($f, 'format'), $args));
    }
}
