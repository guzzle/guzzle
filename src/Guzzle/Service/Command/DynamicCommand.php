<?php

namespace Guzzle\Service\Command;

use Guzzle\Guzzle;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Url;
use Guzzle\Http\UriTemplate;
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
            throw new InvalidArgumentException('An API command must be passed');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        if (!$this->apiCommand->getUri()) {
            $url = $this->getClient()->getBaseUrl();
        } else {

            // Get the path values and use the client config settings
            $variables = $this->getClient()->getConfig()->getAll();
            foreach ($this->apiCommand->getParams() as $name => $arg) {
                $configValue = $this->get($name);
                if (is_scalar($configValue)) {
                    $variables[$name] = $arg->getPrepend() . $configValue . $arg->getAppend();
                }
            }

            // Expand the URI template using the URI values
            $template = new UriTemplate($this->apiCommand->getUri());
            $uri = $template->expand($variables);

            // Merge the client's base URL with the URI template
            $url = Url::factory($this->getClient()->getBaseUrl());
            $url->combine($uri);
            $url = (string) $url;
        }

        // Inject path and base_url values into the URL
        $this->request = $this->getClient()->createRequest($this->apiCommand->getMethod(), $url);

        // Add arguments to the request using the location attribute
        foreach ($this->apiCommand->getParams() as $name => $arg) {

            $configValue = $this->get($name);
            $location = $arg->getLocation();

            if (!$configValue || !$location) {
                continue;
            }

            // Create the value based on prepend and append settings
            $value = $arg->getPrepend() . $configValue . $arg->getAppend();

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
