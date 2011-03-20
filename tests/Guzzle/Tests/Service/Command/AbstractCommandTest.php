<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Command;

use Guzzle\Service\Client;
use Guzzle\Service\Description\XmlDescriptionBuilder;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected function getClient()
    {
        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $service = $builder->build();
        $client =  new Client('http://www.google.com/');
        $client->setDescription($service);

        return $client;
    }
}