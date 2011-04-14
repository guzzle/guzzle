<?php

/**
 * This class was generated using the guzzle client template.  Be sure to
 * update the docblocks, factory method, and add a constructor if needed.
 *
 * @TODO Remove this docblock
 */

namespace ${service.namespace};

use Guzzle\Common\Inspector;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Client;

/**
 * @TODO Update description
 *
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

        return $client;
    }
}