<?php

namespace GuzzleHttp\Service\Guzzle\ResponseLocation;

use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

/**
 * Extracts elements from a JSON document.
 */
class JsonLocation extends AbstractLocation
{
    /** @var array The JSON document being visited */
    private $json = [];

    public function before(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $model,
        &$result,
        array $context = []
    ) {
        $this->json = $response->json();
    }

    public function after(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $model,
        &$result,
        array $context = []
    ) {
        // Handle additional, undefined properties
        $additional = $model->getAdditionalProperties();
        if ($additional instanceof Parameter &&
            $additional->getLocation() == 'json'
        ) {
            foreach ($this->json as $prop => $val) {
                if (!isset($result[$prop])) {
                    $result[$prop] = $this->recursiveProcess($additional, $val);
                }
            }
        }

        $this->json = [];
    }

    public function visit(
        GuzzleCommandInterface $command,
        ResponseInterface $response,
        Parameter $param,
        &$result,
        array $context = []
    ) {
        $name = $param->getName();
        $key = $param->getWireName();

        // Check if the result should be treated as a list
        if ($param->getType() == 'array' &&
            ($context || !$key || $param->getSentAs() === '')
        ) {
            // Treat as javascript array
            if ($context || !$name) {
                // top-level `array` or an empty name
                $result = array_merge(
                    $result,
                    $this->recursiveProcess($param, $this->json)
                );
            } else {
                // name provided, store it under a key in the array
                $result[$name] = $this->recursiveProcess($param, $this->json);
            }
        } elseif (isset($this->json[$key])) {
            // Treat as a javascript object
            if (!$name) {
                $result += $this->recursiveProcess($param, $this->json[$key]);
            } else {
                $result[$name] = $this->recursiveProcess(
                    $param,
                    $this->json[$key]
                );
            }
        }
    }

    /**
     * Recursively process a parameter while applying filters
     *
     * @param Parameter $param API parameter being validated
     * @param mixed     $value Value to process.
     * @return mixed|null
     */
    private function recursiveProcess(Parameter $param, $value)
    {
        if ($value === null) {
            return null;
        } elseif (!is_array($value)) {
            // Scalar values don't need to be walked
            return $param->filter($value);
        }

        $result = [];
        $type = $param->getType();
        if (!$type) {
            // Just merge all properties onto the result
            $result = $value;
        } elseif ($type == 'array') {
            $items = $param->getItems();
            foreach ($value as $val) {
                $result[] = $this->recursiveProcess($items, $val);
            }
        } elseif ($type == 'object' && !isset($value[0])) {
            // On the above line, we ensure that the array is associative and
            // not numerically indexed
            if ($properties = $param->getProperties()) {
                foreach ($properties as $property) {
                    $key = $property->getWireName();
                    if (isset($value[$key])) {
                        $result[$property->getName()] = $this->recursiveProcess(
                            $property,
                            $value[$key]
                        );
                        // Remove from the value so that AP can later be handled
                        unset($value[$key]);
                    }
                }
            }
            // Only check additional properties if everything wasn't already
            // handled
            if ($value) {
                $additional = $param->getAdditionalProperties();
                if ($additional === null || $additional === true) {
                    // Merge the JSON under the resulting array
                    $result += $value;
                } elseif ($additional instanceof Parameter) {
                    // Process all child elements according to the given schema
                    foreach ($value as $prop => $val) {
                        $result[$prop] = $this->recursiveProcess(
                            $additional,
                            $val
                        );
                    }
                }
            }
        }

        return $param->filter($result);
    }
}
