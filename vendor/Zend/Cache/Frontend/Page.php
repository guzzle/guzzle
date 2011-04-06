<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Frontend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Page.php 20096 2010-01-06 02:05:09Z bkarwin $
 */


/**
 * @see Zend_Cache_Core
 */
// require_once 'Zend/Cache/Core.php';


/**
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Frontend
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Cache_Frontend_Page extends Zend_Cache_Core
{
    /**
     * This frontend specific options
     *
     * ====> (boolean) http_conditional :
     * - if true, http conditional mode is on
     * WARNING : http_conditional OPTION IS NOT IMPLEMENTED FOR THE MOMENT (TODO)
     *
     * ====> (boolean) debug_header :
     * - if true, a debug text is added before each cached pages
     *
     * ====> (boolean) content_type_memorization :
     * - deprecated => use memorize_headers instead
     * - if the Content-Type header is sent after the cache was started, the
     *   corresponding value can be memorized and replayed when the cache is hit
     *   (if false (default), the frontend doesn't take care of Content-Type header)
     *
     * ====> (array) memorize_headers :
     * - an array of strings corresponding to some HTTP headers name. Listed headers
     *   will be stored with cache datas and "replayed" when the cache is hit
     *
     * ====> (array) default_options :
     * - an associative array of default options :
     *     - (boolean) cache : cache is on by default if true
     *     - (boolean) cacheWithXXXVariables  (XXXX = 'Get', 'Post', 'Session', 'Files' or 'Cookie') :
     *       if true,  cache is still on even if there are some variables in this superglobal array
     *       if false, cache is off if there are some variables in this superglobal array
     *     - (boolean) makeIdWithXXXVariables (XXXX = 'Get', 'Post', 'Session', 'Files' or 'Cookie') :
     *       if true, we have to use the content of this superglobal array to make a cache id
     *       if false, the cache id won't be dependent of the content of this superglobal array
     *     - (int) specific_lifetime : cache specific lifetime
     *                                (false => global lifetime is used, null => infinite lifetime,
     *                                 integer => this lifetime is used), this "lifetime" is probably only
     *                                usefull when used with "regexps" array
     *     - (array) tags : array of tags (strings)
     *     - (int) priority : integer between 0 (very low priority) and 10 (maximum priority) used by
     *                        some particular backends
     *
     * ====> (array) regexps :
     * - an associative array to set options only for some REQUEST_URI
     * - keys are (pcre) regexps
     * - values are associative array with specific options to set if the regexp matchs on $_SERVER['REQUEST_URI']
     *   (see default_options for the list of available options)
     * - if several regexps match the $_SERVER['REQUEST_URI'], only the last one will be used
     *
     * @var array options
     */
    protected $_specificOptions = array(
        'http_conditional' => false,
        'debug_header' => false,
        'content_type_memorization' => false,
        'memorize_headers' => array(),
        'default_options' => array(
            'cache_with_get_variables' => false,
            'cache_with_post_variables' => false,
            'cache_with_session_variables' => false,
            'cache_with_files_variables' => false,
            'cache_with_cookie_variables' => false,
            'make_id_with_get_variables' => true,
            'make_id_with_post_variables' => true,
            'make_id_with_session_variables' => true,
            'make_id_with_files_variables' => true,
            'make_id_with_cookie_variables' => true,
            'cache' => true,
            'specific_lifetime' => false,
            'tags' => array(),
            'priority' => null
        ),
        'regexps' => array()
    );

    /**
     * Internal array to store some options
     *
     * @var array associative array of options
     */
    protected $_activeOptions = array();

    /**
     * If true, the page won't be cached
     *
     * @var boolean
     */
    protected $_cancel = false;

    /**
     * Constructor
     *
     * @param  array   $options                Associative array of options
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function __construct(array $options = array())
    {
        while (list($name, $value) = each($options)) {
            $name = strtolower($name);
            switch ($name) {
                case 'regexps':
                    $this->_setRegexps($value);
                    break;
                case 'default_options':
                    $this->_setDefaultOptions($value);
                    break;
                case 'content_type_memorization':
                    $this->_setContentTypeMemorization($value);
                    break;
                default:
                    $this->setOption($name, $value);
            }
        }
        if (isset($this->_specificOptions['http_conditional'])) {
            if ($this->_specificOptions['http_conditional']) {
                Zend_Cache::throwException('http_conditional is not implemented for the moment !');
            }
        }
        $this->setOption('automatic_serialization', true);
    }

    /**
     * Specific setter for the 'default_options' option (with some additional tests)
     *
     * @param  array $options Associative array
     * @throws Zend_Cache_Exception
     * @return void
     */
    protected function _setDefaultOptions($options)
    {
        if (!is_array($options)) {
            Zend_Cache::throwException('default_options must be an array !');
        }
        foreach ($options as $key=>$value) {
            if (!is_string($key)) {
                Zend_Cache::throwException("invalid option [$key] !");
            }
            $key = strtolower($key);
            if (isset($this->_specificOptions['default_options'][$key])) {
                $this->_specificOptions['default_options'][$key] = $value;
            }
        }
    }

    /**
     * Set the deprecated contentTypeMemorization option
     *
     * @param boolean $value value
     * @return void
     * @deprecated
     */
    protected function _setContentTypeMemorization($value)
    {
        $found = null;
        foreach ($this->_specificOptions['memorize_headers'] as $key => $value) {
            if (strtolower($value) == 'content-type') {
                $found = $key;
            }
        }
        if ($value) {
            if (!$found) {
                $this->_specificOptions['memorize_headers'][] = 'Content-Type';
            }
        } else {
            if ($found) {
                unset($this->_specificOptions['memorize_headers'][$found]);
            }
        }
    }

    /**
     * Specific setter for the 'regexps' option (with some additional tests)
     *
     * @param  array $options Associative array
     * @throws Zend_Cache_Exception
     * @return void
     */
    protected function _setRegexps($regexps)
    {
        if (!is_array($regexps)) {
            Zend_Cache::throwException('regexps option must be an array !');
        }
        foreach ($regexps as $regexp=>$conf) {
            if (!is_array($conf)) {
                Zend_Cache::throwException('regexps option must be an array of arrays !');
            }
            $validKeys = array_keys($this->_specificOptions['default_options']);
            foreach ($conf as $key=>$value) {
                if (!is_string($key)) {
                    Zend_Cache::throwException("unknown option [$key] !");
                }
                $key = strtolower($key);
                if (!in_array($key, $validKeys)) {
                    unset($regexps[$regexp][$key]);
                }
            }
        }
        $this->setOption('regexps', $regexps);
    }

    /**
     * Start the cache
     *
     * @param  string  $id       (optional) A cache id (if you set a value here, maybe you have to use Output frontend instead)
     * @param  boolean $doNotDie For unit testing only !
     * @return boolean True if the cache is hit (false else)
     */
    public function start($id = false, $doNotDie = false)
    {
        $this->_cancel = false;
        $lastMatchingRegexp = null;
        foreach ($this->_specificOptions['regexps'] as $regexp => $conf) {
            if (preg_match("`$regexp`", $_SERVER['REQUEST_URI'])) {
                $lastMatchingRegexp = $regexp;
            }
        }
        $this->_activeOptions = $this->_specificOptions['default_options'];
        if ($lastMatchingRegexp !== null) {
            $conf = $this->_specificOptions['regexps'][$lastMatchingRegexp];
            foreach ($conf as $key=>$value) {
                $this->_activeOptions[$key] = $value;
            }
        }
        if (!($this->_activeOptions['cache'])) {
            return false;
        }
        if (!$id) {
            $id = $this->_makeId();
            if (!$id) {
                return false;
            }
        }
        $array = $this->load($id);
        if ($array !== false) {
            $data = $array['data'];
            $headers = $array['headers'];
            if (!headers_sent()) {
                foreach ($headers as $key=>$headerCouple) {
                    $name = $headerCouple[0];
                    $value = $headerCouple[1];
                    header("$name: $value");
                }
            }
            if ($this->_specificOptions['debug_header']) {
                echo 'DEBUG HEADER : This is a cached page !';
            }
            echo $data;
            if ($doNotDie) {
                return true;
            }
            die();
        }
        ob_start(array($this, '_flush'));
        ob_implicit_flush(false);
        return false;
    }

    /**
     * Cancel the current caching process
     */
    public function cancel()
    {
        $this->_cancel = true;
    }

    /**
     * callback for output buffering
     * (shouldn't really be called manually)
     *
     * @param  string $data Buffered output
     * @return string Data to send to browser
     */
    public function _flush($data)
    {
        if ($this->_cancel) {
            return $data;
        }
        $contentType = null;
        $storedHeaders = array();
        $headersList = headers_list();
        foreach($this->_specificOptions['memorize_headers'] as $key=>$headerName) {
            foreach ($headersList as $headerSent) {
                $tmp = explode(':', $headerSent);
                $headerSentName = trim(array_shift($tmp));
                if (strtolower($headerName) == strtolower($headerSentName)) {
                    $headerSentValue = trim(implode(':', $tmp));
                    $storedHeaders[] = array($headerSentName, $headerSentValue);
                }
            }
        }
        $array = array(
            'data' => $data,
            'headers' => $storedHeaders
        );
        $this->save($array, null, $this->_activeOptions['tags'], $this->_activeOptions['specific_lifetime'], $this->_activeOptions['priority']);
        return $data;
    }

    /**
     * Make an id depending on REQUEST_URI and superglobal arrays (depending on options)
     *
     * @return mixed|false a cache id (string), false if the cache should have not to be used
     */
    protected function _makeId()
    {
        $tmp = $_SERVER['REQUEST_URI'];
        $array = explode('?', $tmp, 2);
          $tmp = $array[0];
        foreach (array('Get', 'Post', 'Session', 'Files', 'Cookie') as $arrayName) {
            $tmp2 = $this->_makePartialId($arrayName, $this->_activeOptions['cache_with_' . strtolower($arrayName) . '_variables'], $this->_activeOptions['make_id_with_' . strtolower($arrayName) . '_variables']);
            if ($tmp2===false) {
                return false;
            }
            $tmp = $tmp . $tmp2;
        }
        return md5($tmp);
    }

    /**
     * Make a partial id depending on options
     *
     * @param  string $arrayName Superglobal array name
     * @param  bool   $bool1     If true, cache is still on even if there are some variables in the superglobal array
     * @param  bool   $bool2     If true, we have to use the content of the superglobal array to make a partial id
     * @return mixed|false Partial id (string) or false if the cache should have not to be used
     */
    protected function _makePartialId($arrayName, $bool1, $bool2)
    {
        switch ($arrayName) {
        case 'Get':
            $var = $_GET;
            break;
        case 'Post':
            $var = $_POST;
            break;
        case 'Session':
            if (isset($_SESSION)) {
                $var = $_SESSION;
            } else {
                $var = null;
            }
            break;
        case 'Cookie':
            if (isset($_COOKIE)) {
                $var = $_COOKIE;
            } else {
                $var = null;
            }
            break;
        case 'Files':
            $var = $_FILES;
            break;
        default:
            return false;
        }
        if ($bool1) {
            if ($bool2) {
                return serialize($var);
            }
            return '';
        }
        if (count($var) > 0) {
            return false;
        }
        return '';
    }

}
