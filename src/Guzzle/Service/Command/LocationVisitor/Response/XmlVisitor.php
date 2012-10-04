<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;

/**
 * Location visitor used to marshal XML response data into a formatted array
 */
class XmlVisitor extends AbstractResponseVisitor
{
    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, Response $response, Parameter $param, &$value)
    {
        $name = $param->getName();
        if (isset($value[$name])) {
            $this->recursiveProcess($param, $value[$name]);
            $rename = $param->getRename();
            if ($rename && $rename != $name) {
                $value[$rename] = $value[$name];
                unset($value[$name]);
            }
        }
    }

    /**
     * Recursively process a parameter while applying filters
     *
     * @param Parameter $param API parameter being validated
     * @param mixed     $value Value to validate and process. The value may change during this process.
     */
    protected function recursiveProcess(Parameter $param, &$value)
    {
        $type = $param->getType();

        if (is_array($value)) {

            if ($type == 'array') {
                // Convert the node if it was meant to be an array
                if (!isset($value[0])) {
                    // Collections fo nodes are sometimes wrapped in an additional array. For example:
                    // <Items><Item><a>1</a></Item><Item><a>2</a></Item></Items> should become:
                    // array('Items' => array(array('a' => 1), array('a' => 2))
                    // Some nodes are not wrapped. For example: <Foo><a>1</a></Foo><Foo><a>2</a></Foo>
                    // should become array('Foo' => array(array('a' => 1), array('a' => 2))
                    if ($param->getItems() && isset($value[$param->getItems()->getName()])) {
                        // Account for the case of a collection wrapping wrapped nodes: Items => Item[]
                        $value = $value[$param->getItems()->getName()];
                        // If the wrapped node only had one value, then make it an array of nodes
                        if (!isset($value[0]) || !is_array($value)) {
                            $value = array($value);
                        }
                    } elseif (!empty($value)) {
                        // Account for repeated nodes that must be an array: Foo => Baz, Foo => Baz, but only if the
                        // value is set and not empty
                        $value = array($value);
                    }
                }

                foreach ($value as &$item) {
                    $this->recursiveProcess($param->getItems(), $item);
                }

            } elseif ($type == 'object' && !isset($value[0])) {
                // On the above line, we ensure that the array is associative and not numerically indexed
                if ($properties = $param->getProperties()) {
                    foreach ($properties as $property) {
                        $name = $property->getName();
                        if (isset($value[$name])) {
                            $this->recursiveProcess($property, $value[$name]);
                            $rename = $property->getRename();
                            if ($rename && $rename != $name) {
                                $value[$rename] = $value[$name];
                                unset($value[$name]);
                            }
                        }
                    }
                }
            }

        } elseif ($type == 'array') {
            // Cast to an array if the value was a string, but should be an array
            $value = array($value);
        }

        if ($value !== null) {
            $value = $param->filter($value);
        }
    }
}
