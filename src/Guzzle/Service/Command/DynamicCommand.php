<?php

namespace Guzzle\Service\Command;

use Guzzle\Guzzle;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Url;
use Guzzle\Service\Inspector;

/**
 * A command that creates requests based on ApiCommands
 */
class DynamicCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        if (!$this->apiCommand) {
            throw new \InvalidArgumentException('An API command must be passed');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        // Get the path values and use the client config settings
        $pathValues = $this->getClient()->getConfig();
        $foundPath = false;
        foreach ($this->apiCommand->getParams() as $name => $arg) {
            if ($arg->get('location') == 'path') {
                $pathValues->set($name, $arg->get('prepend') . $this->get($name) . $arg->get('append'));
                $foundPath = true;
            }
        }

        // Build a custom URL if there are path values
        if ($foundPath) {
            $path = str_replace('//', '', Guzzle::inject($this->apiCommand->getPath(), $pathValues));
        } else {
            $path = $this->apiCommand->getPath();
        }

        if (!$path) {
            $url = $this->getClient()->getBaseUrl();
        } else {
            $url = Url::factory($this->getClient()->getBaseUrl());
            $url->combine($path);
            $url = (string) $url;
        }

        // Inject path and base_url values into the URL
        $this->request = $this->getClient()->createRequest($this->apiCommand->getMethod(), $url);

        // Add arguments to the request using the location attribute
        foreach ($this->apiCommand->getParams() as $name => $arg) {

            if ($this->get($name)) {

                // Check that a location is set
                $location = $arg->get('location') ?: 'query';

                if ($location == 'path' || $location == 'data') {
                    continue;
                }

                if ($location) {

                    // Create the value based on prepend and append settings
                    $value = $arg->get('prepend') . $this->get($name) . $arg->get('append');

                    // Determine the location and key setting location[:key]
                    $parts = explode(':', $location);
                    $place = $parts[0];

                    // If a key is specified (using location:key), use it
                    $key = isset($parts[1]) ? $parts[1] : $name;

                    // Add the parameter to the request
                    switch ($place) {
                        case 'body':
                            $this->request->setBody(EntityBody::factory($value));
                            break;
                        case 'header':
                            $this->request->setHeader($key, $value);
                            break;
                        case 'query':
                            $this->request->getQuery()->set($key, $value);
                            break;
                    }
                }
            }
        }
    }
}