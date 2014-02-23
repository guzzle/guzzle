<?php

namespace GuzzleHttp\Service\Guzzle\Description;

/**
 * API parameter object used with service descriptions
 */
class Parameter
{
    private $name;
    private $description;
    private $serviceDescription;
    private $type;
    private $required;
    private $enum;
    private $pattern;
    private $minimum;
    private $maximum;
    private $minLength;
    private $maxLength;
    private $minItems;
    private $maxItems;
    private $default;
    private $static;
    private $instanceOf;
    private $filters;
    private $location;
    private $sentAs;
    private $data;
    private $properties = [];
    private $additionalProperties;
    private $items;
    private $parent;
    private $format;
    private $propertiesCache = null;

    /** @var SchemaFormatter */
    private $formatter;

    /**
     * Create a new Parameter using an associative array of data.
     *
     * The array can contain the following information:
     *
     * - name: (string) Unique name of the parameter
     *
     * - type: (string|array) Type of variable (string, number, integer,
     *   boolean, object, array, numeric, null, any). Types are using for
     *   validation and determining the structure of a parameter. You can use a
     *   union type by providing an array of simple types. If one of the union
     *   types matches the provided value, then the value is valid.
     *
     * - instanceOf: (string) When the type is an object, you can specify the
     *   class that the object must implement.
     *
     * - required: (bool) Whether or not the parameter is required
     *
     * - default: (mixed) Default value to use if no value is supplied
     *
     * - static: (bool) Set to true to specify that the parameter value cannot
     *   be changed from the default.
     *
     * - description: (string) Documentation of the parameter
     *
     * - location: (string) The location of a request used to apply a parameter.
     *   Custom locations can be registered with a command, but the defaults
     *   are uri, query, header, body, json, xml, postField, postFile.
     *
     * - sentAs: (string) Specifies how the data being modeled is sent over the
     *   wire. For example, you may wish to include certain headers in a
     *   response model that have a normalized casing of FooBar, but the actual
     *   header is x-foo-bar. In this case, sentAs would be set to x-foo-bar.
     *
     * - filters: (array) Array of static method names to to run a parameter
     *   value through. Each value in the array must be a string containing the
     *   full class path to a static method or an array of complex filter
     *   information. You can specify static methods of classes using the full
     *   namespace class name followed by '::' (e.g. Foo\Bar::baz()). Some
     *   filters require arguments in order to properly filter a value. For
     *   complex filters, use a hash containing a 'method' key pointing to a
     *   static method, and an 'args' key containing an array of positional
     *   arguments to pass to the method. Arguments can contain keywords that
     *   are replaced when filtering a value: '@value' is replaced with the
     *   value being validated, '@api' is replaced with the Parameter object.
     *
     * - properties: When the type is an object, you can specify nested parameters
     *
     * - additionalProperties: (array) This attribute defines a schema for all
     *   properties that are not explicitly defined in an object type
     *   definition. If specified, the value MUST be a schema or a boolean. If
     *   false is provided, no additional properties are allowed beyond the
     *   properties defined in the schema. The default value is an empty schema
     *   which allows any value for additional properties.
     *
     * - items: This attribute defines the allowed items in an instance array,
     *   and MUST be a schema or an array of schemas. The default value is an
     *   empty schema which allows any value for items in the instance array.
     *   When this attribute value is a schema and the instance value is an
     *   array, then all the items in the array MUST be valid according to the
     *   schema.
     *
     * - pattern: When the type is a string, you can specify the regex pattern
     *   that a value must match
     *
     * - enum: When the type is a string, you can specify a list of acceptable
     *   values.
     *
     * - minItems: (int) Minimum number of items allowed in an array
     *
     * - maxItems: (int) Maximum number of items allowed in an array
     *
     * - minLength: (int) Minimum length of a string
     *
     * - maxLength: (int) Maximum length of a string
     *
     * - minimum: (int) Minimum value of an integer
     *
     * - maximum: (int) Maximum value of an integer
     *
     * - data: (array) Any additional custom data to use when serializing,
     *   validating, etc
     *
     * - format: (string) Format used to coax a value into the correct format
     *   when serializing or unserializing. You may specify either an array of
     *   filters OR a format, but not both. Supported values: date-time, date,
     *   time, timestamp, date-time-http.
     *
     * - $ref: (string) String referencing a service description model. The
     *   parameter is replaced by the schema contained in the model.
     *
     * @param array $data    Array of data as seen in service descriptions
     * @param array $options Options used when creating the parameter. You can
     *     specify a Guzzle service description in the 'description' key. You
     *     can specify a custom schema formatter to use in the 'formatter' key.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $data = [], array $options = [])
    {
        if (isset($options['description'])) {
            $this->serviceDescription = $options['description'];
            if (!($this->serviceDescription instanceof GuzzleDescription)) {
                throw new \InvalidArgumentException('description must be a GuzzleDescription');
            }
            if (isset($data['$ref'])) {
                if ($model = $this->serviceDescription->getModel($data['$ref'])) {
                    $data = $model->toArray() + $data;
                }
            } elseif (isset($data['extends'])) {
                // If this parameter extends from another parameter then start
                // with the actual data union in the parent's data (e.g. actual
                // supersedes parent)
                if ($extends = $this->serviceDescription->getModel($data['extends'])) {
                    $data += $extends->toArray();
                }
            }
        }

        // Pull configuration data into the parameter
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }

        $this->required = (bool) $this->required;
        $this->data = (array) $this->data;

        if ($this->filters) {
            $this->setFilters((array) $this->filters);
        }

        if ($this->type == 'object' && $this->additionalProperties === null) {
            $this->additionalProperties = true;
        }

        // Configure the schema formatter
        if (isset($options['formatter'])) {
            $this->formatter = $options['formatter'];
        } else {
            static $defaultFormatter;
            if (!$defaultFormatter) {
                $defaultFormatter = new SchemaFormatter();
            }
            $this->formatter = $defaultFormatter;
        }
    }

    /**
     * Convert the object to an array
     *
     * @return array
     */
    public function toArray()
    {
        static $checks = ['required', 'description', 'static', 'type',
            'format', 'instanceOf', 'location', 'sentAs', 'pattern', 'minimum',
            'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'data',
            'enum', 'filters'];

        $result = [];

        // Anything that is in the `Items` attribute of an array *must* include
        // it's name if available.
        if ($this->parent instanceof self &&
            $this->parent->getType() == 'array' &&
            isset($this->name)
        ) {
            $result['name'] = $this->name;
        }

        foreach ($checks as $c) {
            if ($value = $this->{$c}) {
                $result[$c] = $value;
            }
        }

        if ($this->default !== null) {
            $result['default'] = $this->default;
        }

        if ($this->items !== null) {
            $result['items'] = $this->getItems()->toArray();
        }

        if ($this->additionalProperties !== null) {
            $result['additionalProperties'] = $this->getAdditionalProperties();
            if ($result['additionalProperties'] instanceof self) {
                $result['additionalProperties'] = $result['additionalProperties']->toArray();
            }
        }

        if ($this->type == 'object' && $this->properties) {
            $result['properties'] = [];
            foreach ($this->getProperties() as $name => $property) {
                $result['properties'][$name] = $property->toArray();
            }
        }

        return $result;
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
        if ($this->static || ($this->default !== null && $value === null)) {
            return $this->default;
        }

        return $value;
    }

    /**
     * Run a value through the filters OR format attribute associated with the
     * parameter.
     *
     * @param mixed $value Value to filter
     *
     * @return mixed Returns the filtered value
     */
    public function filter($value)
    {
        // Formats are applied exclusively and supersed filters
        if ($this->format) {
            return $this->formatter->format($this->format, $value);
        }

        // Convert Boolean values
        if ($this->type == 'boolean' && !is_bool($value)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        // Apply filters to the value
        if ($this->filters) {
            foreach ($this->filters as $filter) {
                if (is_array($filter)) {
                    // Convert complex filters that hold value place holders
                    foreach ($filter['args'] as &$data) {
                        if ($data == '@value') {
                            $data = $value;
                        } elseif ($data == '@api') {
                            $data = $this;
                        }
                    }
                    $value = call_user_func_array(
                        $filter['method'],
                        $filter['args']
                    );
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
     * Get the key of the parameter, where sentAs will supersede name if it is
     * set.
     *
     * @return string
     */
    public function getWireName()
    {
        return $this->sentAs ?: $this->name;
    }

    /**
     * Get the type(s) of the parameter
     *
     * @return string|array
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
     * Get the description of the parameter
     *
     * @return string|null
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Get the minimum acceptable value for an integer
     *
     * @return int|null
     */
    public function getMinimum()
    {
        return $this->minimum;
    }

    /**
     * Get the maximum acceptable value for an integer
     *
     * @return int|null
     */
    public function getMaximum()
    {
        return $this->maximum;
    }

    /**
     * Get the minimum allowed length of a string value
     *
     * @return int
     */
    public function getMinLength()
    {
        return $this->minLength;
    }

    /**
     * Get the maximum allowed length of a string value
     *
     * @return int|null
     */
    public function getMaxLength()
    {
        return $this->maxLength;
    }

    /**
     * Get the maximum allowed number of items in an array value
     *
     * @return int|null
     */
    public function getMaxItems()
    {
        return $this->maxItems;
    }

    /**
     * Get the minimum allowed number of items in an array value
     *
     * @return int
     */
    public function getMinItems()
    {
        return $this->minItems;
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
     * Get the sentAs attribute of the parameter that used with locations to
     * sentAs an attribute when it is being applied to a location.
     *
     * @return string|null
     */
    public function getSentAs()
    {
        return $this->sentAs;
    }

    /**
     * Retrieve a known property from the parameter by name or a data property
     * by name. When not specific name value is specified, all data properties
     * will be returned.
     *
     * @param string|null $name Specify a particular property name to retrieve
     *
     * @return array|mixed|null
     */
    public function getData($name = null)
    {
        if (!$name) {
            return $this->data;
        } elseif (isset($this->data[$name])) {
            return $this->data[$name];
        } elseif (isset($this->{$name})) {
            return $this->{$name};
        }

        return null;
    }

    /**
     * Get whether or not the default value can be changed
     *
     * @return mixed|null
     */
    public function getStatic()
    {
        return $this->static;
    }

    /**
     * Get an array of filters used by the parameter
     *
     * @return array
     */
    public function getFilters()
    {
        return $this->filters ?: [];
    }

    /**
     * Get the properties of the parameter
     *
     * @return array
     */
    public function getProperties()
    {
        if (!$this->propertiesCache) {
            $this->propertiesCache = [];
            foreach (array_keys($this->properties) as $name) {
                $this->propertiesCache[$name] = $this->getProperty($name);
            }
        }

        return $this->propertiesCache;
    }

    /**
     * Get a specific property from the parameter
     *
     * @param string $name Name of the property to retrieve
     *
     * @return null|Parameter
     */
    public function getProperty($name)
    {
        if (!isset($this->properties[$name])) {
            return null;
        }

        if (!($this->properties[$name] instanceof self)) {
            $this->properties[$name]['name'] = $name;
            $this->properties[$name] = new static(
                $this->properties[$name],
                $this->serviceDescription
            );
        }

        return $this->properties[$name];
    }

    /**
     * Get the additionalProperties value of the parameter
     *
     * @return bool|Parameter|null
     */
    public function getAdditionalProperties()
    {
        if (is_array($this->additionalProperties)) {
            $this->additionalProperties = new static(
                $this->additionalProperties,
                $this->serviceDescription
            );
        }

        return $this->additionalProperties;
    }

    /**
     * Get the item data of the parameter
     *
     * @return Parameter|null
     */
    public function getItems()
    {
        if (is_array($this->items)) {
            $this->items = new static($this->items, $this->serviceDescription);
        }

        return $this->items;
    }

    /**
     * Get the class that the parameter must implement
     *
     * @return null|string
     */
    public function getInstanceOf()
    {
        return $this->instanceOf;
    }

    /**
     * Get the enum of strings that are valid for the parameter
     *
     * @return array|null
     */
    public function getEnum()
    {
        return $this->enum;
    }

    /**
     * Get the regex pattern that must match a value when the value is a string
     *
     * @return string
     */
    public function getPattern()
    {
        return $this->pattern;
    }

    /**
     * Get the format attribute of the schema
     *
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * Set the array of filters used by the parameter
     *
     * @param array $filters Array of functions to use as filters
     *
     * @return self
     */
    private function setFilters(array $filters)
    {
        $this->filters = [];
        foreach ($filters as $filter) {
            $this->addFilter($filter);
        }

        return $this;
    }

    /**
     * Add a filter to the parameter
     *
     * @param string|array $filter Method to filter the value through
     *
     * @return self
     * @throws \InvalidArgumentException
     */
    private function addFilter($filter)
    {
        if (is_array($filter)) {
            if (!isset($filter['method'])) {
                throw new \InvalidArgumentException(
                    'A [method] value must be specified for each complex filter'
                );
            }
        }

        if (!$this->filters) {
            $this->filters = [$filter];
        } else {
            $this->filters[] = $filter;
        }

        return $this;
    }
}
