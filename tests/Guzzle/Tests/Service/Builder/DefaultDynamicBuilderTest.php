<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Builder;

use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Common\CacheAdapter\DoctrineCacheAdapter;
use Guzzle\Service\Builder\DefaultDynamicBuilder;

/**
 * @group Builder
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DefaultDynamicBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Builder\DefaultDynamicBuilder
     */
    public function testConstructor()
    {
        $builder = new DefaultDynamicBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'test_service.xml', array(
            'username' => 'michael',
            'password' => 'test',
            'subdomain' => 'michael'
        ));

        $this->assertEquals('Test Service Builder', $builder->getName());
        $this->assertEquals('Guzzle\\Service\\Client', $builder->getClass());
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultDynamicBuilder
     */
    public function testBuildsClients()
    {
        $builder = new DefaultDynamicBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'test_service.xml', array(
            'username' => 'michael',
            'password' => 'test',
            'subdomain' => 'michael'
        ));
        
        $client = $builder->build();
        $this->assertTrue($client->getService()->hasCommand('test'));

        $command = $client->getCommand('test', array(
            'bucket' => 'test',
            'key' => 'key'
        ));

        $this->assertInstanceOf('Guzzle\\Service\\Command\\ClosureCommand', $command);
        
        $command->prepare();

        $request = $command->getRequest();
        $this->assertEquals('DELETE', $request->getMethod());
        $this->assertEquals('www.test.com', $request->getHost());
        $this->assertEquals('/test/key.json', $request->getPath());
    }
}