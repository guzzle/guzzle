<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws;

use Guzzle\Service\Aws\Mws\MwsBuilder;
use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Service\Aws\Mws\MwsBuilder
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class MwsBuilderTest extends GuzzleTestCase
{
    public function testBuild()
    {
        $builder = new MwsBuilder(array(
            'merchant_id'           => 'ASDF',
            'marketplace_id'        => 'ASDF',
            'access_key_id'         => 'ASDF',
            'secret_access_key'     => 'ASDF',
            'application_name'      => 'GuzzleTest',
            'application_version'   => '0.1'
        ));

        $client = $builder->build();
    }
}