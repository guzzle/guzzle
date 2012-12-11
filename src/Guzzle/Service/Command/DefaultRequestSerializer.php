<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;
use Guzzle\Service\Command\LocationVisitor\Request\RequestVisitorInterface;
use Guzzle\Service\Command\LocationVisitor\Request\BodyVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\ResponseBodyVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\HeaderVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\JsonVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\QueryVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\PostFieldVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\PostFileVisitor;
use Guzzle\Service\Command\LocationVisitor\Request\XmlVisitor;

/**
 * Default request serializer that transforms command options and operation parameters into a request
 */
class DefaultRequestSerializer implements RequestSerializerInterface
{
    /**
     * @var array Location visitors attached to the command
     */
    protected $visitors = array();

    /**
     * @var array Cached instance with default visitors
     */
    protected static $instance;

    /**
     * Get a default instance that includes that default location visitors
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self(array(
                'header'        => new HeaderVisitor(),
                'query'         => new QueryVisitor(),
                'body'          => new BodyVisitor(),
                'json'          => new JsonVisitor(),
                'postFile'      => new PostFileVisitor(),
                'postField'     => new PostFieldVisitor(),
                'xml'           => new XmlVisitor(),
                'response_body' => new ResponseBodyVisitor()
            ));
        }

        return self::$instance;
    }

    /**
     * @param array $visitors Visitors to attache
     */
    public function __construct(array $visitors = array())
    {
        $this->visitors = $visitors;
    }

    /**
     * Add a location visitor to the command
     *
     * @param string                   $location Location to associate with the visitor
     * @param RequestVisitorInterface  $visitor  Visitor to attach
     *
     * @return self
     */
    public function addVisitor($location, RequestVisitorInterface $visitor)
    {
        $this->visitors[$location] = $visitor;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(CommandInterface $command)
    {
        $operation = $command->getOperation();
        $client = $command->getClient();
        $uri = $operation->getUri();

        if (!$uri) {
            $url = $client->getBaseUrl();
        } else {
            // Get the path values and use the client config settings
            $variables = $client->getConfig()->getAll();
            foreach ($operation->getParams() as $name => $arg) {
                if ($arg->getLocation() == 'uri' && $command->hasKey($name)) {
                    $variables[$name] = $command->get($name);
                    if (!is_array($variables[$name])) {
                        $variables[$name] = (string) $variables[$name];
                    }
                }
            }
            // Merge the client's base URL with an expanded URI template
            $url = (string) Url::factory($client->getBaseUrl())
                ->combine(ParserRegistry::getInstance()->getParser('uri_template')->expand($uri, $variables));
        }

        // Inject path and base_url values into the URL
        $request = $client->createRequest($operation->getHttpMethod(), $url);

        // Add arguments to the request using the location attribute
        foreach ($operation->getParams() as $name => $arg) {
            /** @var $arg \Guzzle\Service\Description\Parameter */
            $location = $arg->getLocation();
            // Visit with the associated visitor
            if (isset($this->visitors[$location])) {
                // Ensure that a value has been set for this parameter
                $value = $command->get($name);
                if ($value !== null) {
                    // Apply the parameter value with the location visitor
                    $this->visitors[$location]->visit($command, $request, $arg, $value);
                }
            }
        }

        // Call the after method on each visitor
        foreach ($this->visitors as $visitor) {
            $visitor->after($command, $request);
        }

        return $request;
    }
}
