<?php

namespace Guzzle\Service\Description;

/**
 * API parameter object used with service descriptions
 */
class ApiParam
{
    protected $name;
    protected $type;
    protected $typeArgs;
    protected $required;
    protected $default;
    protected $doc;
    protected $minLength;
    protected $maxLength;
    protected $location;
    protected $locationKey;
    protected $static;
    protected $prepend;
    protected $append;
    protected $filters;
    protected $structure = array();
    protected $parent;

    /**
     * Create a new ApiParam using an associative array of data
     *
     * @param array $data Array of data as seen in service descriptions
     */
    public function __construct(array $data)
    {
        $this->name = isset($data['name']) ? $data['name'] : null;
        $this->required = array_key_exists('required', $data) ? $data['required'] : false;
        $this->default = isset($data['default']) ? $data['default'] : null;
        $this->doc = isset($data['doc']) ? $data['doc'] : null;
        $this->static = isset($data['static']) ? $data['static'] : null;
        $this->prepend = isset($data['prepend']) ? $data['prepend'] : null;
        $this->append = isset($data['append']) ? $data['append'] : null;
        $this->minLength = isset($data['min_length']) ? $data['min_length'] : null;
        $this->maxLength = isset($data['max_length']) ? $data['max_length'] : null;
        $this->filters = isset($data['filters']) ? (array) $data['filters'] : array();

        if (isset($data['type'])) {
            if (strpos($data['type'], ':')) {
                list($this->type, $data['type_args']) = explode(':', $data['type'], 2);
            } else {
                $this->type = $data['type'];
            }
        }

        $this->typeArgs = isset($data['type_args']) ? (array) $data['type_args'] : null;

        if (isset($data['location'])) {
            if (strpos($data['location'], ':')) {
                list($this->location, $this->locationKey) = explode(':', $data['location'], 2);
            } else {
                $this->location = $data['location'];
            }
        }

        if (isset($data['location_key'])) {
            $this->locationKey = $data['location_key'];
        }

        // Use the name of the parameter as the location key by default
        if (!$this->locationKey) {
            $this->locationKey = $this->name;
        }

        $this->parent = isset($data['parent']) ? $data['parent'] : null;

        // Create nested structures recursively
        if (isset($data['structure'])) {
            foreach ($data['structure'] as $name => $structure) {
                // Allow the name to be set in the key or in the sub params
                if (empty($structure['name'])) {
                    $structure['name'] = $name;
                }
                $structure['parent'] = $this;
                $this->addStructure(new static($structure));
            }
        }
    }

    /**
     * Convert the object to an array
     *
     * @return array
     */
    public function toArray()
    {
        $structure = array();
        foreach ($this->structure as $name => $struct) {
            $structure[$name] = $struct->toArray();
        }

        return array(
            'name'         => $this->name,
            'type'         => $this->type,
            'type_args'    => $this->typeArgs,
            'required'     => $this->required,
            'default'      => $this->default,
            'doc'          => $this->doc,
            'min_length'   => $this->minLength,
            'max_length'   => $this->maxLength,
            'location'     => $this->location,
            'location_key' => $this->locationKey,
            'static'       => $this->static,
            'prepend'      => $this->prepend,
            'append'       => $this->append,
            'filters'      => $this->filters,
            'structure'    => $structure
        );
    }

    /**
     * Get the default or static value of the command based on a value
     *
     * @param string $value Value that is currently set
     *
     * @return mixed Returns the value, a static value if one is present, or a default value
     */
    public function getValue($value)
    {
        return $this->static !== null
            || ($this->default !== null && !$value && ($this->type != 'bool' || $value !== false))
            ? ($this->static ?: $this->default)
            : $value;
    }

    /**
     * Filter a value
     *
     * @param mixed $value Value to filter
     *
     * @return mixed Returns the filtered value
     */
    public function filter($value)
    {
        if (!empty($this->filters)) {
            foreach ($this->filters as $filter) {
                if (is_array($filter)) {
                    // Convert complex filters that hold value place holders
                    foreach ($filter['args'] as &$data) {
                        if ($data == '@value') {
                            $data = $value;
                        }
                    }
                    $value = call_user_func_array($filter['method'], $filter['args']);
                } else {
                    $value = call_user_func($filter, $value);
                }
            }
        }

        return $value;
    }

    /**
     * Get the name of the parameter
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the name of the parameter
     *
     * @param string $name Name to set
     *
     * @return self
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set the type
     *
     * @param string $type Type of parameter
     *
     * @return self
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the arguments to pass to type constraint
     *
     * @return array|null
     */
    public function getTypeArgs()
    {
        return $this->typeArgs;
    }

    /**
     * Set the arguments to pass to type constraint
     *
     * @param array $args Type arguments
     *
     * @return self
     */
    public function setTypeArgs(array $args)
    {
        $this->typeArgs = $args;

        return $this;
    }

    /**
     * Get if the parameter is required
     *
     * @return bool
     */
    public function getRequired()
    {
        return $this->required;
    }

    /**
     * Set if the parameter is required
     *
     * @param bool $isRequired Whether or not the parameter is required
     *
     * @return self
     */
    public function setRequired($isRequired)
    {
        $this->required = $isRequired;

        return $this;
    }

    /**
     * Get the default value of the parameter
     *
     * @return string|null
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * Set the default value of the parameter
     *
     * @param string|null $default Default value to set
     *
     * @return self
     */
    public function setDefault($default)
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Get the docs for the parameter
     *
     * @return string|null
     */
    public function getDoc()
    {
        return $this->doc;
    }

    /**
     * Set the docs for the parameter
     *
     * @param string $docs Documentation
     *
     * @return self
     */
    public function setDoc($docs)
    {
        $this->doc = $docs;

        return $this;
    }

    /**
     * Get the minimum allowed length of the parameter
     *
     * @return int|null
     */
    public function getMinLength()
    {
        return $this->minLength;
    }

    /**
     * Set the minimum allowed length of the parameter
     *
     * @param int|null $minLength Minimum length of the parameter
     *
     * @return self
     */
    public function setMinLength($minLength)
    {
        $this->minLength = $minLength;

        return $this;
    }

    /**
     * Get the maximum allowed length of the parameter
     *
     * @return int|null
     */
    public function getMaxLength()
    {
        return $this->maxLength;
    }

    /**
     * Set the maximum allowed length of the parameter
     *
     * @param int|null $maxLength Maximum length of the parameter
     *
     * @return self
     */
    public function setMaxLength($maxLength)
    {
        $this->maxLength = $maxLength;

        return $this;
    }

    /**
     * Get the location of the parameter
     *
     * @return string|null
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * Set the location of the parameter
     *
     * @param string|null $location Location of the parameter
     *
     * @return self
     */
    public function setLocation($location)
    {
        $this->location = $location;

        return $this;
    }

    /**
     * Get the location key mapping of the parameter
     *
     * @return string|null
     */
    public function getLocationKey()
    {
        return $this->locationKey;
    }

    /**
     * Set the location key mapping of the parameter
     *
     * @param string|null $key Location key
     *
     * @return self
     */
    public function setLocationKey($key)
    {
        $this->locationKey = $key;

        return $this;
    }

    /**
     * Get the static value of the parameter that cannot be changed
     *
     * @return mixed|null
     */
    public function getStatic()
    {
        return $this->static;
    }

    /**
     * Set the static value of the parameter that cannot be changed
     *
     * @param mixed|null $static Static value to set
     *
     * @return self
     */
    public function setStatic($static)
    {
        $this->static = $static;

        return $this;
    }

    /**
     * Get the string to prepend to values
     *
     * @return string
     */
    public function getPrepend()
    {
        return $this->prepend;
    }

    /**
     * Set the string to prepend to values
     *
     * @param string|null $prepend String to prepend to values
     *
     * @return self
     */
    public function setPrepend($prepend)
    {
        $this->prepend = $prepend;

        return $this;
    }

    /**
     * Get the string to append to values
     *
     * @return string
     */
    public function getAppend()
    {
        return $this->append;
    }

    /**
     * Set the string to append to values
     *
     * @param string|null $append String to append to values
     *
     * @return self
     */
    public function setAppend($append)
    {
        $this->append = $append;

        return $this;
    }

    /**
     * Get an array of filters used by the parameter
     *
     * @return array
     */
    public function getFilters()
    {
        return $this->filters;
    }

    /**
     * Set the array of filters used by the parameter
     *
     * @param array $filters Array of functions to use as filters
     *
     * @return self
     */
    public function setFilters(array $filters)
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * Add a filter to the parameter
     *
     * @param string $filter Method to filter the value through
     *
     * @return self
     */
    public function addFilter($filter)
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Get the structure of the parameter or a specific structure element
     *
     * @param string $paramName Specific parameter to retrieve
     *
     * @return array|ApiParam|null
     */
    public function getStructure($paramName = null)
    {
        return $paramName
            ? (isset($this->structure[$paramName]) ? $this->structure[$paramName] : null)
            : $this->structure;
    }

    /**
     * Add an element to the structure of the parameter
     *
     * @param ApiParam $param Parameter to add
     *
     * @return self
     */
    public function addStructure(ApiParam $param)
    {
        $this->structure[$param->getName()] = $param;
        $param->setParent($this);

        return $this;
    }

    /**
     * Remove the structure of the parameter or a specific element from the structure
     *
     * @param string $paramName Specific parameter to remove by name
     *
     * @return self
     */
    public function removeStructure($paramName = null)
    {
        if ($paramName) {
            unset($this->structure[$paramName]);
        } else {
            $this->structure = array();
        }

        return $this;
    }

    /**
     * Get the parent object (an {@see ApiCommand} or {@see ApiParam}
     *
     * @return ApiCommandInterface|ApiParam|null
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set the parent object of the parameter
     *
     * @param ApiCommandInterface|ApiParam|null $parent Parent container of the parameter
     *
     * @return self
     */
    public function setParent($parent)
    {
        $this->parent = $parent;

        return $this;
    }
}
