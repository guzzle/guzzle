<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\EntityBody;
use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;
use Guzzle\Http\Message\PostFileInterface;

/**
 * A command that creates requests based on {see ApiCommandInterface} objects
 */
class DynamicCommand extends AbstractCommand
{
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
            $uri = ParserRegistry::get('uri_template')->expand($this->apiCommand->getUri(), $variables);

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
            if ($arg->getPrepend() || $arg->getAppend()) {
                $value = $arg->getPrepend() . $configValue . $arg->getAppend();
            } else {
                $value = $configValue;
            }

            // If a location key mapping is set, then use it
            $key = $arg->getLocationKey() ?: $name;

            // Add the parameter to the request
            switch ($location) {
                case 'body':
                    $this->request->setBody(EntityBody::factory($value));
                    break;
                case 'header':
                    $this->request->setHeader($key, $value);
                    break;
                case 'query':
                    $this->request->getQuery()->set($key, $value);
                    break;
                case 'post_field':
                    $this->request->setPostField($key, $value);
                    break;
                case 'post_file':
                    if ($value instanceof PostFileInterface) {
                        $this->request->addPostFile($value);
                    } else {
                        $this->request->addPostFile($key, $value);
                    }
                    break;
            }
        }
    }
}
