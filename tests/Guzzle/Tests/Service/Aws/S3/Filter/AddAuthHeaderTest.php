<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Filter;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AddAuthHeaderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Filter\AddAuthHeader
     */
    public function testFilter()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        $builder = new \Guzzle\Service\Aws\S3\S3Builder(array(
            'base_url' => $this->getServer()->getUrl(),
            'access_key_id' => 'a',
            'secret_access_key' => 's'
        ));

        $client = $builder->build();

        $request = $client->getRequest('GET');
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\S3\\Filter\\AddAuthHeader'));
        $request->send();

        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertContains('AWS a:', $request->getHeader('Authorization'));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Filter\AddAuthHeader
     */
    public function testFilterSkipsIfNoSignatureObjectIsProvided()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $request = $client->getRequest('GET');

        $filter = new \Guzzle\Service\Aws\S3\Filter\AddAuthHeader(array());
        $this->assertFalse($filter->process($request));
    }
}