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
     * @var string
     */
    protected $baseNamespace;

    /**
     * @var InflectorInterface Inflector used to determine class names
     */
    protected $inflector;

    /**
     * @param string             $baseNamespace Base namespace of all iterator object.
     * @param InflectorInterface $inflector     Inflector used to resolve class names
     */
    public function __construct($baseNamespace, InflectorInterface $inflector = null)
    {
        $this->baseNamespace = $baseNamespace;
        $this->inflector = $inflector ?: Inflector::getDefault();
    }

    /**
     * Create a resource iterator
     *
     * @param CommandInterface $data    Command used for building the iterator
     * @param array            $options Iterator options.
     *
     * @return ResourceIteratorInterface
     */
    public function build($data, array $options = null)
    {
        if (!($data instanceof CommandInterface)) {
            throw new InvalidArgumentException('The first argument must be an instance of CommandInterface');
        }

        // Determine the name of the class to load
        $className = $this->baseNamespace . '\\' . $this->inflector->camel($data->getName()) . 'Iterator';

        return new $className($data, $options);
    }
}
