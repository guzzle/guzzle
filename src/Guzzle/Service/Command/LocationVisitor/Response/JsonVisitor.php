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
        $name   = $param->getName();
        $sentAs = $param->getSentAs();
        $key    = $param->getWireName();

        $treatAsList = $param->getType() == 'array' && (empty($key) || ($sentAs === '') || $context);

        if($treatAsList) {
            // Treat as javascript array
            if ($context || empty($name)) {
                // top-level `array` or an empty name
                $value = array_merge($value, $this->recursiveProcess($param, $this->json));
            } else {
                // name provided, store it under a key in the array
                $value[$name] = $this->recursiveProcess($param, $this->json);
            }
        } elseif (isset($this->json[$key])) {
            // Treat as a javascript object
            if (empty($name)) {
                $value = array_merge($value, $this->recursiveProcess($param, $this->json[$key]));
            } else {
                $value[$name] = $this->recursiveProcess($param, $this->json[$key]);
            }
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
            // skipping the already processed property
            $json = $this->json;
            unset($json[$key]);
            $value = array_merge($json, $value);
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

                $additional = $param->getAdditionalProperties();
                if ($additional instanceof Parameter) {
                    // Process all child elements according to the given schema
                    foreach ($value as $prop => $val) {
                        if (is_int($prop)) {
                            $result[] = $this->recursiveProcess($additional, $val);
                        } elseif ($prop != $key) {
                            $result[$prop] = $this->recursiveProcess($additional, $val);
                        }
                    }
                } elseif ($additional === null || $additional === true) {
                    // Blindly merge the JSON into resulting array
                    // skipping the already processed property
                    $value = array_diff_key($value, $knownProperties);
                    $result = array_merge($value, $result);
                }
            }
        } else {
            // A scalar
            $result = $value;
        }

        $result = $param->filter($result);

        return $result;
    }

    public function after(CommandInterface $command)
    {
        // Free up memory
        $this->json = array();
    }
}
