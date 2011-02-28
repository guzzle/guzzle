<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SignS3RequestPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\SignS3RequestPlugin
     */
    public function testSignsS3Requests()
    {
        $signature = new \Guzzle\Service\Aws\S3\S3Signature('a', 'b');
        $plugin = new \Guzzle\Service\Aws\S3\SignS3RequestPlugin($signature);
        $this->assertSame($signature, $plugin->getSignature());

        $request = \Guzzle\Http\Message\RequestFactory::getInstance()->newRequest('GET', 'http://www.test.com/');

        $mediator = new \Guzzle\Common\Subject\SubjectMediator($request);
        $mediator->notify('request.create', $request);

        $plugin->update($mediator);
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\S3\\Filter\\AddAuthHeader'));
    }
}