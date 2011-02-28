<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SimpleDbBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\SimpleDbBuilder::build
     * @covers Guzzle\Service\Aws\SimpleDb\SimpleDbBuilder::getClass
     */
    public function testBuild()
    {
        $builder = $this->getServiceBuilder()->getBuilder('test.simple_db');

        $this->assertInstanceOf('Guzzle\Service\Aws\SimpleDb\SimpleDbBuilder', $builder);
        $this->assertEquals('Guzzle\\Service\\Aws\\SimpleDb\\SimpleDbClient', $builder->getClass());
        $this->assertEquals('test.simple_db', $builder->getName());

        // Make sure the builder creates a valid client objects
        $client = $builder->build();
        $this->assertInstanceOf('Guzzle\\Service\\Aws\\SimpleDb\\SimpleDbClient', $client);

        // Make sure the query string auth signing plugin was attached
        $this->assertTrue($client->hasPlugin('Guzzle\Service\Aws\QueryStringAuthPlugin'));
    }
}