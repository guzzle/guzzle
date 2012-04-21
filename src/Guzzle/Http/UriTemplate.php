<?php

namespace Guzzle\Http;

/**
 * Expands URI templates using an array of variables
 *
 * @link http://tools.ietf.org/html/draft-gregorio-uritemplate-08
 */
class UriTemplate
{
    private $template;
    private $variables;
    private $regex = '/\{{1,2}([^\}]+)\}{1,2}/';
    private static $operators = array('+', '#', '.', '/', ';', '?', '&');
    private static $delims = array(':', '/', '?', '#', '[', ']', '@', '!', '$', '&', '\'', '(', ')', '*', '+', ',', ';', '=');
    private static $delimsPct = array('%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D');

    /**
     * @param string $template (optional) URI template to expand
     */
    public function __construct($template = '')
    {
        $this->template = $template;
    }

    /**
     * Get the URI template
     *
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * Set the URI template
     *
     * @param string $template URI template to expand
     *
     * @return UriTemplate
     */
    public function setTemplate($template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Expand the URI template using the supplied variables
     *
     * @param array $variables Variables to use with the expansion
     *
     * @return string Returns the expanded template
     */
    public function expand(array $variables)
    {
        $this->variables = $variables;

        return preg_replace_callback($this->regex, array($this, 'expandMatch'), $this->template);
    }

    /**
     * Set the regular expression used to identify URI templates
     *
     * @param string $regex Regular expression
     *
     * @return UriTemplate
     */
    public function setRegex($regex)
    {
        $this->regex = $regex;

        return $this;
    }

    /**
     * Parse an expression into parts
     *
     * @param string $expression Expression to parse
     *
     * @return array Returns an associative array of parts
     */
    private function parseExpression($expression)
    {
        // Check for URI operators
        $operator = '';
        if (in_array($expression[0], self::$operators)) {
            $operator = $expression[0];
            $expression = substr($expression, 1);
        }

        return array(
            'operator' => $operator,
            'values'   => array_map(function($value) {
                $value = trim($value);
                $varspec = array();
                $substrPos = strpos($value, ':');
                if ($substrPos) {
                    $varspec['value'] = substr($value, 0, $substrPos);
                    $varspec['modifier'] = ':';
                    $varspec['position'] = (int) substr($value, $substrPos + 1);
                } else if (substr($value, -1) == '*') {
                    $varspec['modifier'] = '*';
                    $varspec['value'] = substr($value, 0, -1);
                } else {
                    $varspec['value'] = (string) $value;
                    $varspec['modifier'] = '';
                }
                return $varspec;
            }, explode(',', $expression))
        );
    }

    /**
     * Process an expansion
     *
     * @param array $matches Matches met in the preg_replace_callback
     *
     * @return string Returns the replacement string
     */
    private function expandMatch(array $matches)
    {
        $parsed = self::parseExpression($matches[1]);
        $replacements = array();

        $prefix = $parsed['operator'];
        $joiner = $parsed['operator'];
        $useQueryString = false;
        if ($parsed['operator'] == '?') {
            $joiner = '&';
            $useQueryString = true;
        } else if ($parsed['operator'] == '&') {
            $useQueryString = true;
        } else if ($parsed['operator'] == '#') {
            $joiner = ',';
        } else if ($parsed['operator'] == ';') {
            $useQueryString = true;
        } else if ($parsed['operator'] == '' || $parsed['operator'] == '+') {
            $joiner = ',';
            $prefix = '';
        }

        foreach ($parsed['values'] as $value) {

            if (!array_key_exists($value['value'], $this->variables)) {
                continue;
            }

            $variable = $this->variables[$value['value']];
            $actuallyUseQueryString = $useQueryString;
            $expanded = '';

            if (is_array($variable)) {

                $isAssoc = $this->isAssoc($variable);
                $kvp = array();
                foreach ($variable as $key => $var) {
                    if ($isAssoc) {
                        $key = rawurlencode($key);
                    }
                    $var = rawurlencode($var);
                    if ($parsed['operator'] == '+' || $parsed['operator'] == '#') {
                        $var = $this->decodeReserved($var);
                    }

                    if ($value['modifier'] == '*') {
                        if ($isAssoc) {
                            $var = $key . '=' . $var;
                        } else if ($key > 0 && $actuallyUseQueryString) {
                            $var = $value['value'] . '=' . $var;
                        }
                    }

                    $kvp[$key] = $var;
                }

                if ($value['modifier'] == '*') {
                    $expanded = implode($joiner, $kvp);
                    if ($isAssoc) {
                        // Don't prepend the value name when using the explode
                        // modifier with an associative array
                        $actuallyUseQueryString = false;
                    }
                } else {
                    if ($isAssoc) {
                        // When an associative array is encountered and the
                        // explode modifier is not set, then the result must
                        // be a comma separated list of keys followed by their
                        // respective values.
                        foreach ($kvp as $k => &$v) {
                            $v = $k . ',' . $v;
                        }
                    }
                    $expanded = implode(',', $kvp);
                }

            } else {
                if ($value['modifier'] == ':') {
                    $variable = substr($variable, 0, $value['position']);
                }
                $expanded = rawurlencode($variable);
                if ($parsed['operator'] == '+' || $parsed['operator'] == '#') {
                    $expanded = $this->decodeReserved($expanded);
                }
            }

            if ($actuallyUseQueryString) {
                if (!$expanded && $joiner != '&') {
                    $expanded = $value['value'];
                } else {
                    $expanded = $value['value'] . '=' . $expanded;
                }
            }

            $replacements[] = $expanded;
        }

        $ret = implode($joiner, $replacements);
        if ($ret && $prefix) {
            return $prefix . $ret;
        }

        return $ret;
    }

    /**
     * Determines if an array is associative
     *
     * @param array $array Array to check
     *
     * @return bool
     */
    private function isAssoc(array $array)
    {
        return (bool) count(array_filter(array_keys($array), 'is_string'));
    }

    /**
     * Removes percent encoding on reserved characters (used with + and # modifiers)
     *
     * @param string $string String to fix
     *
     * @return string
     */
    private function decodeReserved($string)
    {
        return str_replace(self::$delimsPct, self::$delims, $string);
    }
}
