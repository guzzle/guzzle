<?php

namespace Guzzle\Service\Builder;

use Guzzle\Service\Exception\ServiceBuilderException;

/**
 * Creates a ServiceBuilder using a XML configuration file
 */
class XmlServiceBuilderFactory implements ServiceBuilderFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build($config, array $options = null)
    {
        return ServiceBuilder::factory($this->parseXmlFile($config), $options);
    }

    /**
     * Parse an XML document into an array
     *
     * @param string $filename Path to the file
     *
     * @return array
     */
    protected function parseXmlFile($filename)
    {
        if ($filename instanceof \SimpleXMLElement) {
            $xml = $filename;
        } else {
            $xml = new \SimpleXMLElement($filename, null, true);
            $result = array(
                'services' => array()
            );
        }

        // Account for old style service builder config files
        if (isset($xml->services)) {
            $services = $xml->services->service;
        } else {
            $services = $xml->clients->client;
        }

        foreach ($services as $service) {
            $row = array();
            foreach ($service->param as $param) {
                $row[(string) $param->attributes()->name] = (string) $param->attributes()->value;
            }
            $result['services'][(string) $service->attributes()->name] = array(
                'class'   => (string) $service->attributes()->class,
                'extends' => (string) $service->attributes()->extends,
                'params'  => $row
            );
        }

        // Include any XML files under the includes elements
        if (isset($xml->includes->include)) {

            // You can only extend other services when using a file
            if ($filename instanceof \SimpleXMLElement) {
                throw new ServiceBuilderException('You can not extend other SimpleXMLElement services');
            }

            foreach ($xml->includes->include as $include) {
                $path = (string) $include->attributes()->path;
                if ($path[0] != DIRECTORY_SEPARATOR) {
                    $path = dirname($filename) . DIRECTORY_SEPARATOR . $path;
                }
                $result = array_merge_recursive($this->parseXmlFile($path), $result);
            }
        }

        // Grab the class name if it's set
        if (isset($xml->class)) {
            $result['class'] = str_replace('.', '\\', (string) $xml->class);
        }

        return $result;
    }
}
