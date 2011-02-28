<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Builder;

use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Common\CacheAdapter\DoctrineCacheAdapter;
use Guzzle\Service\Builder\ServiceBuilder;

/**
 * @group service
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ServiceBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $xmlConfig;
    protected $tempFile;

    public function __construct()
    {
        $this->xmlConfig = <<<EOT
<?xml version="1.0" ?>
<guzzle>
    <clients>
        <client name="michael.unfuddle" builder="Guzzle.Service.Builder.DefaultBuilder" class="Guzzle.Service.Unfuddle.UnfuddleClient">
            <param name="username" value="michael" />
            <param name="password" value="testing123" />
            <param name="subdomain" value="michael" />
        </client>
        <client name="billy.unfuddle" builder="Guzzle.Service.Builder.DefaultBuilder" class="Guzzle.Service.Unfuddle.UnfuddleClient">
            <param name="username" value="billy" />
            <param name="password" value="passw0rd" />
            <param name="subdomain" value="billy" />
        </client>
    </clients>
</guzzle>
EOT;

        $this->tempFile = tempnam('/tmp', 'config.xml');
        file_put_contents($this->tempFile, $this->xmlConfig);
    }

    public function __destruct()
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     * @covers Guzzle\Service\Builder\ServiceBuilder::getBuilder
     */
    public function testCanBeCreatedUsingAnXmlFile()
    {
        $builder = ServiceBuilder::factory($this->tempFile);
        $b = $builder->getBuilder('michael.unfuddle');
        $this->assertInstanceOf('Guzzle\\Service\\Builder\\DefaultBuilder', $b);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     * @expectedException Guzzle\Service\ServiceException
     * @expectedExceptionMessage Unable to open service configuration file foobarfile
     */
    public function testFactoryEnsuresItCanOpenFile()
    {
        ServiceBuilder::factory('foobarfile');
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     */
    public function testFactoryThrowsExceptionWhenBuilderExtendsNonExistentBuilder()
    {
        $xml = '<?xml version="1.0" ?>' . "\n" . '<guzzle><clients><client name="invalid" extends="missing" /></clients></guzzle>';
        $tempFile = tempnam('/tmp', 'config.xml');
        file_put_contents($tempFile, $xml);

        try {
            ServiceBuilder::factory($tempFile);
            unlink($tempFile);
            $this->fail('Test did not throw ServiceException');
        } catch (\Guzzle\Service\ServiceException $e) {
            $this->assertEquals('invalid is trying to extend a non-existent or not yet defined service: missing', $e->getMessage());
        }

        unlink($tempFile);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     * @covers Guzzle\Service\Builder\ServiceBuilder::setCache
     * @covers Guzzle\Service\Builder\ServiceBuilder
     */
    public function testFactoryUsesCacheAdapterWhenAvailable()
    {
        $cache = new ArrayCache();
        $adapter = new DoctrineCacheAdapter($cache);
        $this->assertEmpty($cache->getIds());

        $s1 = ServiceBuilder::factory($this->tempFile, $adapter, 86400);
        
        // Make sure it added to the cache
        $this->assertNotEmpty($cache->getIds());

        // Load this one from cache
        $s2 = ServiceBuilder::factory($this->tempFile, $adapter, 86400);

        $builder = ServiceBuilder::factory($this->tempFile);
        $this->assertEquals($s1, $s2);

        $this->assertSame($s1, $s1->setCache($adapter, 86400));
        $client = $s1->getClient('michael.unfuddle');
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::getBuilder
     */
    public function testBuildersAreStoredForPerformance()
    {
        $builder = ServiceBuilder::factory($this->tempFile);
        $b = $builder->getBuilder('michael.unfuddle');
        $this->assertTrue($b === $builder->getBuilder('michael.unfuddle'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::getBuilder
     * @expectedException Guzzle\Service\ServiceException
     * @expectedExceptionMessage No service builder is registered as foobar
     */
    public function testThrowsExceptionWhenGettingInvalidBuilder()
    {
        ServiceBuilder::factory($this->tempFile)->getBuilder('foobar');
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::getClient
     */
    public function testGetClientStoresClientCopy()
    {
        $builder = ServiceBuilder::factory($this->tempFile);
        $client = $builder->getClient('michael.unfuddle');
        $this->assertInstanceOf('Guzzle\\Service\\Unfuddle\\UnfuddleClient', $client);
        $this->assertEquals('https://michael.unfuddle.com/api/v1/', $client->getBaseUrl());
        $this->assertEquals($client, $builder->getClient('michael.unfuddle'));

        // Get another client but throw this one away
        $client2 = $builder->getClient('billy.unfuddle', true);
        $this->assertInstanceOf('Guzzle\\Service\\Unfuddle\\UnfuddleClient', $client2);
        $this->assertEquals('https://billy.unfuddle.com/api/v1/', $client2->getBaseUrl());

        // Make sure the original client is still there and set
        $this->assertTrue($client === $builder->getClient('michael.unfuddle'));

        // Create a new billy.unfuddle client that is stored
        $client3 = $builder->getClient('billy.unfuddle');
        
        // Make sure that the stored billy.unfuddle client is equal to the other stored client
        $this->assertTrue($client3 === $builder->getClient('billy.unfuddle'));

        // Make sure that this client is not equal to the previous throwaway client
        $this->assertFalse($client2 === $builder->getClient('billy.unfuddle'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::getBuilder
     * @expectedException Guzzle\Service\ServiceException
     * @expectedExceptionMessage A class attribute must be present when using Guzzle\Service\Builder\DefaultBuilder
     */
    public function testThrowsExceptionWhenGettingDefaultBuilderWithNoClassSpecified()
    {
        $s = new ServiceBuilder(array(
            'michael.unfuddle' => array(
                'builder' => 'Guzzle.Service.Builder.DefaultBuilder',
                'params' => array(
                    'username' => 'michael'
                )
            )
        ));

        $s->getBuilder('michael.unfuddle');
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder
     */
    public function testBuildersPassOptionsThroughToClients()
    {
        $s = new ServiceBuilder(array(
            'michael.unfuddle' => array(
                'builder' => 'Guzzle.Service.Builder.DefaultBuilder',
                'class' => 'Guzzle.Service.Unfuddle.UnfuddleClient',
                'params' => array(
                    'subdomain' => 'michael',
                    'password' => 'test',
                    'username' => 'michael',
                    'curl.curlopt_proxyport' => 8080
                )
            )
        ));

        $c = $s->getBuilder('michael.unfuddle')->build();
        $this->assertEquals(8080, $c->getConfig('curl.curlopt_proxyport'));
    }
}