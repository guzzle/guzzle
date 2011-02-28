<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http;

/**
 * Cookie contains cookies and allows the easy access, removal, and
 * string representation of HTTP cookies that will be sent in an HTTP request.
 *
 * This class can be used to generate a Cookie Version 0 request header.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
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
        $data = array();
        if ($cookieString) {
            foreach (explode(';', $cookieString) as $kvp) {
                $parts = explode('=', $kvp);
                $key = urldecode(trim($parts[0]));
                $value = (isset($parts[1])) ? trim($parts[1]) : '';
                $data[$key] = urldecode($value);
            }
        }

        return new self($data);
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
             ->setEncodeFields(true)
             ->setEncodeValues(true);
    }
}