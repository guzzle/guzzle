<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Builder;

use Guzzle\Common\Collection;
use Guzzle\Service\ServiceException;
use Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder;
use Guzzle\Service\Command\DynamicCommandFactory;

/**
 * Default service client builder for dynamic services based on a service
 * document
 *
 * @author  michael@guzzlephp.org
 */
class DefaultDynamicBuilder extends AbstractBuilder
{
    /**
     * @var CommandFactory Factory to build commands based on a description
     */
    protected $commandFactory;

    /**
     * @var ServiceDescription Service document describing the service
     */
    protected $service;

    /**
     * @var string Class name
     */
    protected $class;

    /**
     * Construct the DynamicClient builder using an XML document
     *
     * @param string $filename Full path to the service description document
     * @param array $config (optional) Configuration values to apply
     */
    public function __construct($filename, array $config = null)
    {
        $builder = new XmlDescriptionBuilder($filename);
        $this->service = $builder->build();
        $clientArgs = $this->service->getClientArgs();
        $this->class = $clientArgs['_client_class']['value'];
        $this->commandFactory = new DynamicCommandFactory($this->service);
        $this->name = $this->service->getName() . ' Builder';
        $this->config = $config ?: array();
    }

    /**
     * Build the client
     *
     * @return DynamicClient
     */
    public function build()
    {
        $class = $this->getClass();
        $client = new $class($this->service->getBaseUrl());
        $client->setConfig($this->config)
               ->setService($this->service)
               ->setCommandFactory($this->commandFactory);

        return $client;
    }

    /**
     * {@inheritdoc}
     */
    public function getClass()
    {
        return $this->class;
    }
}