<?php

namespace Guzzle\Service\Command\LocationVisitor\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Command\CommandInterface;

/**
 * Location visitor used to marshal JSON response data into a formatted array.
 *
 * Allows top level JSON parameters to be inserted into the result of a command. The top level attributes are grabbed
 * from the response's JSON data using the name value by default. Filters can be applied to parameters as they are
 * traversed. This allows data to be normalized before returning it to users (for example converting timestamps to
 * DateTime objects).
 */
class JsonVisitor extends AbstractResponseVisitor
{
    /**
     * The JSON document being visited
     *
     * @var array
     */
    protected $json = array();

    public function before(CommandInterface $command, array &$result)
    {
        // Parse JSON from command response
        $this->json = $command->getResponse()->json();
    }

    public function visit(
        CommandInterface $command,
        Response $response,
        Parameter $param,
        &$value,
        $context = null
    )
    {
        $name = $param->getName();
        $key = $param->getWireName();
        if (isset($this->json[$key])) {
            $value[$name] = $this->recursiveProcess($param, $this->json[$key]);
        }

        // Handle additional, undefined properties
        $additional = $param->getAdditionalProperties();
        if ($additional instanceof Parameter) {
            // Process all child elements according to the given schema
            foreach ($this->json as $prop => $val) {
                if (is_int($prop)) {
                    $value[] = $this->recursiveProcess($additional, $val);
                } elseif ($prop != $key) {
                    $value[$prop] = $this->recursiveProcess($additional, $val);
                }
            }
        } elseif ($additional === null || $additional === true) {
            // Blindly merge the JSON into resulting array
            $value = array_merge($this->json, $value);
        }
    }

    /**
     * Recursively process a parameter while applying filters
     *
     * @param Parameter $param API parameter being validated
     * @param mixed     $value Value to validate and process. The value may change during this process.
     * @return mixed|null
     */
    protected function recursiveProcess(Parameter $param, $value)
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $result = array();
            $type = $param->getType();
            if ($type == 'array') {
                $items = $param->getItems();
                foreach ($value as $val) {
                    $result[] = $this->recursiveProcess($items, $val);
                }
            } elseif ($type == 'object' && !isset($value[0])) {
                // On the above line, we ensure that the array is associative and not numerically indexed
                $knownProperties = array();
                if ($properties = $param->getProperties()) {
                    foreach ($properties as $property) {
                        $name = $property->getName();
                        $key = $property->getWireName();
                        $knownProperties[$name] = 1;
                        if (isset($value[$key])) {
                            $result[$name] = $this->recursiveProcess($property, $value[$key]);
                        }
                    }
                }

                // Remove any unknown and potentially unsafe properties
                if ($param->getAdditionalProperties() === false) {
                    $result = array_intersect_key($result, $knownProperties);
                }
            }
        } else {
            // A scalar
            $result = $value;
        }

        $result = $param->filter($result);

        return $result;
    }

    /**
     * @param array $json
     */
    public function setJson(array $json)
    {
        $this->json = $json;
    }

    /**
     * @return array
     */
    public function getJson()
    {
        return $this->json;
    }


}
