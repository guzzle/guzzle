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

    /**
     * Create a new ApiParam using an associative array of data
     *
     * @param array $data Array of data as seen in service descriptions
     */
    public function __construct(array $data)
    {
        // Parse snake_case into camelCase class properties and parse :'d values
        foreach ($data as $key => $value) {
            if ($key == 'type' && strpos($value, ':')) {
                list($this->type, $this->typeArgs) = explode(':', $value, 2);
            } elseif ($key == 'location' && strpos($value, ':')) {
                list($this->location, $this->locationKey) = explode(':', $value, 2);
            } elseif ($key == 'min_length') {
                $this->minLength = $value;
            } elseif ($key == 'max_length') {
                $this->maxLength = $value;
            } elseif ($key == 'location_key') {
                $this->locationKey = $value;
            } elseif ($key == 'type_args') {
                $this->typeArgs = $value;
            } else {
                $this->{$key} = $value;
            }
        }

        $this->filters = $this->filters ? self::parseFilters($this->filters) : array();
        $this->required = filter_var($this->required, FILTER_VALIDATE_BOOLEAN);

        // Parse CSV type value data into an array
        if ($this->typeArgs && is_string($this->typeArgs)) {
            if (strpos($this->typeArgs, ',') !== false) {
                $this->typeArgs = str_getcsv($this->typeArgs, ',', "'");
            } else {
                $this->typeArgs = array($this->typeArgs);
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
            'filters'      => implode(',', $this->filters)
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
        if ($this->static || ($this->default && !$value)) {
            $check = $this->static ?: $this->default;
            if ($check === 'true') {
                return true;
            } elseif ($check === 'false') {
                return false;
            } else {
                return $check;
            }
        }

        return $value;
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
        if ($this->filters) {
            foreach ($this->filters as $filter) {
                $value = call_user_func($filter, $value);
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
     * @param string|null $location Location of the paramter
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
     * Sanitize and parse a ``filters`` value
     *
     * @param string $filter Filter to sanitize
     *
     * @return array
     */
    protected static function parseFilters($filter)
    {
        $filters = explode(',', $filter);
        foreach ($filters as &$filter) {
            $filter = trim(str_replace('.', '\\', $filter));
        }

        return $filters;
    }
}
