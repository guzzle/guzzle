<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\DescriptionBuilder;

use Guzzle\Common\Inflector;
use Guzzle\Common\Inspector;
use Guzzle\Service\ApiCommand;
use Guzzle\Service\ServiceDescription;

/**
 * Build service descriptions by crawling a source tree and using Reflection on
 * the classes in the source tree of commands.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ConcreteDescriptionBuilder implements DescriptionBuilderInterface
{
    const COMMAND_INTERFACE = 'Guzzle\\Service\\Command\\CommandInterface';
    const GUZZLE_ANNOTATION = '@guzzle';

    /**
     * @var string Location of Commands
     */
    private $path;

    /**
     * @var string Relative namespace of each command
     */
    private $relativeNamespace;

    /**
     * @var string Name of the service
     */
    private $name;

    /**
     * @var string Description of the service
     */
    private $description;

    /**
     * @var array Array of Client constructor arguments
     */
    private $clientArgs;

    /**
     * @var string Base URL of the service
     */
    private $baseUrl = '';

    /**
     * Create a new ConcreteDescriptionBuilder
     * 
     * @param string $clientClass The client class to build the commands from
     * @param string $baseUrl (optional) Base URL to pass to the description
     * 
     * @throws ReflectionException if the client cannot be found
     */
    public function __construct($clientClass, $baseUrl = '')
    {
        // Use reflection on the client class to get information about the service
        $reflection = new \ReflectionClass($clientClass);
        $this->relativeNamespace = $reflection->getNamespaceName() . '\\Command';
        $this->path = dirname($reflection->getFileName()) . DIRECTORY_SEPARATOR . 'Command';
        $this->name = str_replace(array($reflection->getNamespaceName(), 'Client', '\\'), '', $reflection->getName());
        
        // Parse the client's docBlock for information
        $parsed = Inspector::getInstance()->parseDocBlock($reflection->getDocComment());
        $this->description = $parsed['doc'];
        $this->clientArgs = $parsed['args'];
        $this->baseUrl = $baseUrl;
    }

    /**
     * Builds a new ServiceDescription object
     *
     * @return ServiceDescription
     */
    public function build()
    {
        $commands = array();
        $service = '';
        $iterator = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path)), '/^.+\.php$/i', \RecursiveRegexIterator::GET_MATCH);

        // Iterate over all of the .php files in the specified directory
        foreach ($iterator as $name => $file) {

            $className = str_replace(array('.php', '/'), array('', '\\'), $name);
            $className = $this->relativeNamespace . '\\' . substr($className, strpos($className, 'Command\\') + 8);

            $reflection = new \ReflectionClass($className);

            // Make sure that this is not an abstract class and implements
            // the CommandInterface interface
            if (!$reflection->isAbstract() && $reflection->isSubclassOf(self::COMMAND_INTERFACE)) {

                // Generate the action name using subdirectories separated
                // by periods and snake case formatting
                $action = str_replace('\\_', '.', Inflector::snake(str_replace($this->relativeNamespace . '\\', '', $className)));

                // Parse the docblock of the class and gather the arguments
                $data = Inspector::getInstance()->parseDocBlock($reflection->getDocComment());

                $canBatch = isset($data['args']['_can_batch']) ? $data['args']['_can_batch']['value'] : false;

                // Remove internal arguments
                foreach ($data['args'] as $name => $value) {
                    if (strpos($name, '_') === 0) {
                        unset($data['args'][$name]);
                    }
                }

                $commands[] = new ApiCommand(array(
                    'name' => $action,
                    'doc' => $data['doc'],
                    'can_batch' => $canBatch,
                    'concrete_command_class' => $className,
                    'args' => $data['args']
                ));
            }
        }

        return new ServiceDescription($this->name, $this->description, $this->baseUrl, $commands, $this->clientArgs);
    }
}