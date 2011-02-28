<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Filter;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DevPayTokenHeadersTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Filter\DevPayTokenHeaders
     */
    public function testFilter()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        
        $builder = new \Guzzle\Service\Aws\S3\S3Builder(array(
            'base_url' => $this->getServer()->getUrl(),
            'access_key_id' => 'a',
            'secret_access_key' => 's',
            'devpay_user_token' => 'user',
            'devpay_product_token' => 'product'
        ));

        $client = $builder->build();

        $request = $client->getRequest('GET');
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\S3\\Filter\\DevPayTokenHeaders'));
        $request->send();

        $this->assertTrue($request->hasHeader('x-amz-security-token'));
        $this->assertEquals('user, product', $request->getHeader('x-amz-security-token'));
    }
}