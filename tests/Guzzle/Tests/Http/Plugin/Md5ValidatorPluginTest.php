<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Plugin\Md5ValidatorPlugin;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Md5ValidatorPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Plugin\Md5ValidatorPlugin
     */
    public function testValidatesMd5()
    {
        $plugin = new Md5ValidatorPlugin();
        $request = RequestFactory::get('http://www.test.com/');
        $request->getEventManager()->attach($plugin);

        $body = 'abc';
        $hash = md5($body);
        $response = new Response(200, array(
            'Content-MD5' => $hash,
            'Content-Length' => 3
        ), 'abc');

        $request->getEventManager()->notify('request.complete', $response);
    }

    /**
     * @covers Guzzle\Http\Plugin\Md5ValidatorPlugin
     * @expectedException UnexpectedValueException
     */
    public function testThrowsExceptionOnInvalidMd5()
    {
        $plugin = new Md5ValidatorPlugin();
        $request = RequestFactory::get('http://www.test.com/');
        $request->getEventManager()->attach($plugin);

        $request->getEventManager()->notify('request.complete',
            new Response(200, array(
                'Content-MD5' => 'foobar',
                'Content-Length' => 3
            ), 'abc')
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\Md5ValidatorPlugin
     */
    public function testSkipsWhenContentLengthIsTooLarge()
    {
        $plugin = new Md5ValidatorPlugin(false, 1);
        $request = RequestFactory::get('http://www.test.com/');
        $request->getEventManager()->attach($plugin);

        $request->getEventManager()->notify('request.complete',
            new Response(200, array(
                'Content-MD5' => 'foobar',
                'Content-Length' => 3
            ), 'abc')
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\Md5ValidatorPlugin
     */
    public function testProperlyValidatesWhenUsingContentEncoding()
    {
        $plugin = new Md5ValidatorPlugin(true);
        $request = RequestFactory::get('http://www.test.com/');
        $request->getEventManager()->attach($plugin);

        // Content-MD5 is the MD5 hash of the canonical content after all
        // content-encoding has been applied.  Because cURL will automaticall
        // decompress entity bodies, we need to re-compress it to calculate.
        $body = EntityBody::factory('abc');
        $body->compress();
        $hash = $body->getContentMd5();
        $body->uncompress();

        $response = new Response(200, array(
            'Content-MD5' => $hash,
            'Content-Encoding' => 'gzip'
        ), 'abc');
        $request->getEventManager()->notify('request.complete', $response);
        $this->assertEquals('abc', $response->getBody(true));

        // Try again with an unknown encoding
        $response = new Response(200, array(
            'Content-MD5' => $hash,
            'Content-Encoding' => 'foobar'
        ), 'abc');
        $request->getEventManager()->notify('request.complete', $response);

        // Try again with compress
        $body->compress('bzip2.compress');
        $response = new Response(200, array(
            'Content-MD5' => $body->getContentMd5(),
            'Content-Encoding' => 'compress'
        ), 'abc');
        $request->getEventManager()->notify('request.complete', $response);
    }
}