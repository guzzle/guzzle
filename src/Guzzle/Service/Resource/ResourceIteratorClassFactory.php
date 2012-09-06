<?php

namespace Guzzle\Service\Resource;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Inflection\InflectorInterface;
use Guzzle\Common\Inflection\Inflector;
use Guzzle\Service\Command\CommandInterface;

/**
 * Factory for creating {@see ResourceIteratorInterface} objects using a
 * convention of storing iterator classes under a root namespace using the
 * name of a {@see CommandInterface} object as a convention for determining
 * the name of an iterator class.  The command name is converted to CamelCase
 * and Iterator is appended -- (e.g. camel_case => CamelCaseIterator).
 */
class ResourceIteratorClassFactory implements ResourceIteratorFactoryInterface
{
    /**
     * @var array List of namespaces used to look for classes
     */
    protected $namespaces;

    /**
     * @var InflectorInterface Inflector used to determine class names
     */
    protected $inflector;

    /**
     * @param string|array       $namespaces List of namespaces for iterator objects
     * @param InflectorInterface $inflector  Inflector used to resolve class names
     */
    public function __construct($namespaces = array(), InflectorInterface $inflector = null)
    {
        $this->namespaces = (array) $namespaces;
        $this->inflector = $inflector ?: Inflector::getDefault();
    }

    /**
     * Registers a namespace to check for Iterators
     *
     * @param string $namespace Namespace which contains Iterator classes
     *
     * @return self
     */
    public function registerNamespace($namespace)
    {
        array_unshift($this->namespaces, $namespace);

        return $this;
    }

    /**
     * Create a resource iterator
     *
     * @param CommandInterface $data    Command used for building the iterator
     * @param array            $options Iterator options that are exposed as data.
     *
     * @return ResourceIteratorInterface
     */
    public function build($data, array $options = array())
    {
        if (!($data instanceof CommandInterface)) {
            throw new InvalidArgumentException('The first argument must be an instance of CommandInterface');
        }

        $iteratorName = $this->inflector->camel($data->getName()) . 'Iterator';

        // Determine the name of the class to load
        $className = null;
        foreach ($this->namespaces as $namespace) {
            $potentialClassName = $namespace . '\\' . $iteratorName;
            if (class_exists($potentialClassName)) {
                $className = $potentialClassName;
                break;
            }
        }

        if (!$className) {
            throw new InvalidArgumentException("Iterator was not found matching {$iteratorName}");
        }

        return new $className($data, $options);
    }
}
