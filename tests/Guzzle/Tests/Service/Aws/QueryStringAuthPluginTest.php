<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws;

use Guzzle\Common\Subject\SubjectMediator;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Service\Aws\Signature\SignatureV2;
use Guzzle\Service\Aws\QueryStringAuthPlugin;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class QueryStringAuthPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\QueryStringAuthPlugin
     * @covers Guzzle\Service\Aws\Filter\AddRequiredQueryStringFilter
     * @covers Guzzle\Service\Aws\Filter\QueryStringSignatureFilter
     */
    public function testAddsQueryStringAuth()
    {
        $signature = new SignatureV2('a', 'b');
        
        $plugin = new QueryStringAuthPlugin($signature, '2009-04-15');
        $this->assertSame($signature, $plugin->getSignature());
        $this->assertEquals('2009-04-15', $plugin->getApiVersion());

        $request = RequestFactory::getInstance()->newRequest('GET', 'http://www.test.com/');

        $mediator = new SubjectMediator($request);
        $mediator->notify('request.create', $request);

        $plugin->update($mediator);
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\Filter\\AddRequiredQueryStringFilter'));
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\Filter\\QueryStringSignatureFilter'));

        $request->getPrepareChain()->process($request);
        $qs = $request->getQuery();
        $this->assertTrue($qs->hasKey('Timestamp'));
        $this->assertEquals('2009-04-15', $qs->get('Version'));
        $this->assertEquals('2', $qs->get('SignatureVersion'));
        $this->assertEquals('HmacSHA256', $qs->get('SignatureMethod'));
        $this->assertEquals('a', $qs->get('AWSAccessKeyId'));
    }
}