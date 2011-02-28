<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws;

use Guzzle\Service\Aws\AbstractBuilder;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder;
use Guzzle\Service\Aws\Signature\SignatureV2;
use Guzzle\Service\Aws\QueryStringAuthPlugin;

/**
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class MwsBuilder extends AbstractBuilder
{
    protected $signature;
    protected $endpoint;

    const VERSION = '2009-01-01';

    /**
     * Build client
     *
     * @return MwsClient
     */
    public function build()
    {
        if (!$this->signature) {
            $this->signature = new SignatureV2($this->config->get('access_key_id'), $this->config->get('secret_access_key'));
        }

        $builder = new ConcreteDescriptionBuilder($this->getClass(), $this->config->get('base_url'));
        $serviceDescription = $builder->build();
        $commandFactory = new ConcreteCommandFactory($serviceDescription);

        $client = new MwsClient($this->config, $serviceDescription, $commandFactory);
        $client->attachPlugin(new QueryStringAuthPlugin($this->signature, $this->config->get('version', self::VERSION)));

        return $client;
    }

    /**
     * {@inheritdoc}
     */
    public function getClass()
    {
        return 'Guzzle\\Service\\Aws\\Mws\\MwsClient';
    }
}