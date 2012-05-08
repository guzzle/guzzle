<?php

namespace Guzzle\Service\Description;

/**
 * API parameter object used with service descriptions
 */
class ApiParam
{
    protected $name;
    protected $type;
    protected $required;
    protected $default;
    protected $doc;
    protected $minLength;
    protected $maxLength;
    protected $location;
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
        // Parse snake_case into camelCase class properties
        foreach ($data as $key => $value) {
            if ($key == 'min_length') {
                $key = 'minLength';
            } elseif ($key == 'max_length') {
                $key = 'maxLength';
            }
            $this->{$key} = $value;
        }

        if ($this->filters) {
            $this->filters = array_map('trim', explode(',', $this->filters));
        } else {
            $this->filters = array();
        }

        if ($this->required === 'false') {
            $this->required = false;
        } elseif ($this->required === 'true') {
            $this->required = true;
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
            'name'       => $this->name,
            'type'       => $this->type,
            'required'   => $this->required,
            'default'    => $this->default,
            'doc'        => $this->doc,
            'min_length' => $this->minLength,
            'max_length' => $this->maxLength,
            'location'   => $this->location,
            'static'     => $this->static,
            'prepend'    => $this->prepend,
            'append'     => $this->append,
            'filters'    => implode(',', $this->filters)
        );
    }

    /**
     * Get the default or static value of the command based on a value
     *
     * @param string $value Value that is currently set
     *
     * @return mixed Returns the value, a static value if one is present, or a
     *     default value if nothing is provided.
     */
    public function getValue($value)
    {
        if ($this->static || ($this->default && !$value)) {
            $check = $this->static ?: $this->default;
            if ($check === 'true') {
                return true;
            } else if ($check === 'false') {
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
     * Get the type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
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
     * Get the default value of the parameter
     *
     * @return string|null
     */
    public function getDefault()
    {
        return $this->default;
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
     * Get the minimum allowed length of the parameter
     *
     * @return int|null
     */
    public function getMinLength()
    {
        return $this->minLength;
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
     * Get the location of the parameter
     *
     * @return int|null
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * Get the static value of the parameter
     *
     * @return int|null
     */
    public function getStatic()
    {
        return $this->static;
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
     * Get the string to append to values
     *
     * @return string
     */
    public function getAppend()
    {
        return $this->append;
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
}
