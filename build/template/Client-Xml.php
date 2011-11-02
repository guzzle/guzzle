<?php

namespace ${service.namespace};

use Guzzle\Common\Inspector;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Client;
use Guzzle\Service\Description\XmlDescriptionBuilder;

/**
 * @author ${service.author} <${service.email}>
 */
class ${service.client_class} extends Client
{
    /**
     * Factory method to create a new ${service.client_class}
     *
     * @param array|Collection $config Configuration data. Array keys:
     *    base_url - Base URL of web service
     *
     * @return ${service.client_class}
     *
     * @TODO update factory method and docblock for parameters
     */
    public static function factory($config)
    {
        $default = array();
        $required = array('base_url');
        $config = Inspector::prepareConfig($config, $default, $required);

        $client = new self($config->get('base_url'));
        $client->setConfig($config);

        // Add the XML service description to the client
        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . 'client.xml');
        $client->setDescription($builder->build());

        return $client;
    }
}