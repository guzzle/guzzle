<?php

namespace GuzzleHttp\Service\Guzzle\RequestLocation;

use GuzzleHttp\Service\Guzzle\Description\Operation;
use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;

abstract class AbstractLocation implements RequestLocationInterface
{
    /** @var string */
    protected $locationName;

    /**
     * Set the name of the location
     *
     * @param $locationName
     */
    public function __construct($locationName)
    {
        $this->locationName = $locationName;
    }

    public function visit(
        RequestInterface $request,
        Parameter $param,
        $value,
        array $context
    ) {}

    public function after(
        GuzzleCommandInterface $command,
        RequestInterface $request,
        Operation $operation,
        array $context
    ) {}

    /**
     * Prepare (filter and set desired name for request item) the value for
     * request.
     *
     * @param mixed     $value
     * @param Parameter $param
     *
     * @return array|mixed
     */
    protected function prepareValue($value, Parameter $param)
    {
        return is_array($value)
            ? $this->resolveRecursively($value, $param)
            : $param->filter($value);
    }

    /**
     * Recursively prepare and filter nested values.
     *
     * @param array     $value Value to map
     * @param Parameter $param Parameter related to the current key.
     *
     * @return array Returns the mapped array
     */
    protected function resolveRecursively(array $value, Parameter $param)
    {
        foreach ($value as $name => &$v) {
            switch ($param->getType()) {
                case 'object':
                    if ($subParam = $param->getProperty($name)) {
                        $key = $subParam->getWireName();
                        $value[$key] = $this->prepareValue($v, $subParam);
                        if ($name != $key) {
                            unset($value[$name]);
                        }
                    } elseif ($param->getAdditionalProperties() instanceof Parameter) {
                        $v = $this->prepareValue($v, $param->getAdditionalProperties());
                    }
                    break;
                case 'array':
                    if ($items = $param->getItems()) {
                        $v = $this->prepareValue($v, $items);
                    }
                    break;
            }
        }

        return $param->filter($value);
    }
}
