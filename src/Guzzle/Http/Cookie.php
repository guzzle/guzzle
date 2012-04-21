<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;

/**
 * Cookie contains cookies and allows the easy access, removal, and
 * string representation of HTTP cookies that will be sent in an HTTP request.
 */
class Cookie extends QueryString
{
    /**
     * Create a Cookie by parsing a Cookie HTTP header
     *
     * @param string $cookieString Cookie HTTP header
     *
     * @return Cookie
     */
    public static function factory($cookieString)
    {
        $data = new Collection();
        if ($cookieString) {
            foreach (explode(';', $cookieString) as $kvp) {
                $parts = explode('=', $kvp, 2);
                $key = urldecode(trim($parts[0]));
                $value = isset($parts[1]) ? trim($parts[1]) : '';
                $data->add($key, urldecode($value));
            }
        }

        return new static($data->getAll());
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(array $data = null)
    {
        parent::__construct($data);

        $this->setFieldSeparator(';')
             ->setPrefix('')
             ->setValueSeparator('=')
             ->setEncodeFields(false)
             ->setEncodeValues(false)
             ->setAggregateFunction(function($key, $value, $encodeFields = false, $encodeValues = false) {
                 $value = array_unique($value);
                 return array(
                    (($encodeFields) ? rawurlencode($key) : $key) => (($encodeValues)
                        ? array_map('rawurlencode', $value)
                        : $value)
                );
            });
    }
}
