<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Event\ListenerAttacherTrait;
use GuzzleHttp\Post\PostBody;
use GuzzleHttp\Post\PostFile;
use GuzzleHttp\Post\PostFileInterface;
use GuzzleHttp\Query;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Cookie;
use GuzzleHttp\Subscriber\HttpError;
use GuzzleHttp\Subscriber\Redirect;
use GuzzleHttp\Url;
use \InvalidArgumentException as Iae;

/**
 * Default HTTP request factory used to create Request and Response objects.
 */
class MessageFactory implements MessageFactoryInterface
{
    use ListenerAttacherTrait;

    /** @var HttpError */
    private $errorPlugin;

    /** @var Redirect */
    private $redirectPlugin;

    /** @var array */
    private $customOptions;

    /** @var array Request options passed through to request Config object */
    private static $configMap = [
        'connect_timeout' => 1, 'timeout' => 1, 'verify' => 1, 'ssl_key' => 1,
        'cert' => 1, 'proxy' => 1, 'debug' => 1, 'save_to' => 1, 'stream' => 1,
        'expect' => 1, 'future' => 1
    ];

    /** @var array Default allow_redirects request option settings  */
    private static $defaultRedirect = [
        'max'       => 5,
        'strict'    => false,
        'referer'   => false,
        'protocols' => ['http', 'https']
    ];

    /**
     * @param array $customOptions Associative array of custom request option
     *                             names mapping to functions used to apply
     *                             the option. The function accepts the request
     *                             and the option value to apply.
     */
    public function __construct(array $customOptions = [])
    {
        $this->errorPlugin = new HttpError();
        $this->redirectPlugin = new Redirect();
        $this->customOptions = $customOptions;
    }

    public function createResponse(
        $statusCode,
        array $headers = [],
        $body = null,
        array $options = []
    ) {
        if (null !== $body) {
            $body = Stream::factory($body);
        }

        return new Response($statusCode, $headers, $body, $options);
    }

    public function createRequest($method, $url, array $options = [])
    {
        // Handle the request protocol version option that needs to be
        // specified in the request constructor.
        if (isset($options['version'])) {
            $options['config']['protocol_version'] = $options['version'];
            unset($options['version']);
        }

        $request = new Request($method, $url, [], null,
            isset($options['config']) ? $options['config'] : []);

        unset($options['config']);

        // Use a POST body by default
        if (strtoupper($method) == 'POST'
            && !isset($options['body'])
            && !isset($options['json'])
        ) {
            $options['body'] = [];
        }

        if ($options) {
            $this->applyOptions($request, $options);
        }

        return $request;
    }

    /**
     * Create a request or response object from an HTTP message string
     *
     * @param string $message Message to parse
     *
     * @return RequestInterface|ResponseInterface
     * @throws \InvalidArgumentException if unable to parse a message
     */
    public function fromMessage($message)
    {
        static $parser;
        if (!$parser) {
            $parser = new MessageParser();
        }

        // Parse a response
        if (strtoupper(substr($message, 0, 4)) == 'HTTP') {
            $data = $parser->parseResponse($message);
            return $this->createResponse(
                $data['code'],
                $data['headers'],
                $data['body'] === '' ? null : $data['body'],
                $data
            );
        }

        // Parse a request
        if (!($data = ($parser->parseRequest($message)))) {
            throw new \InvalidArgumentException('Unable to parse request');
        }

        return $this->createRequest(
            $data['method'],
            Url::buildUrl($data['request_url']),
            [
                'headers' => $data['headers'],
                'body' => $data['body'] === '' ? null : $data['body'],
                'config' => [
                    'protocol_version' => $data['protocol_version']
                ]
            ]
        );
    }

    /**
     * Apply POST fields and files to a request to attempt to give an accurate
     * representation.
     *
     * @param RequestInterface $request Request to update
     * @param array            $body    Body to apply
     */
    protected function addPostData(RequestInterface $request, array $body)
    {
        static $fields = ['string' => true, 'array' => true, 'NULL' => true,
            'boolean' => true, 'double' => true, 'integer' => true];

        $post = new PostBody();
        foreach ($body as $key => $value) {
            if (isset($fields[gettype($value)])) {
                $post->setField($key, $value);
            } elseif ($value instanceof PostFileInterface) {
                $post->addFile($value);
            } else {
                $post->addFile(new PostFile($key, $value));
            }
        }

        if ($request->getHeader('Content-Type') == 'multipart/form-data') {
            $post->forceMultipartUpload(true);
        }

        $request->setBody($post);
    }

    protected function applyOptions(
        RequestInterface $request,
        array $options = []
    ) {
        $config = $request->getConfig();
        $emitter = $request->getEmitter();

        foreach ($options as $key => $value) {

            if (isset(self::$configMap[$key])) {
                $config[$key] = $value;
                continue;
            }

            switch ($key) {

            case 'allow_redirects':

                if ($value === false) {
                    continue;
                }

                if ($value === true) {
                    $value = self::$defaultRedirect;
                } elseif (!is_array($value)) {
                    throw new Iae('allow_redirects must be true, false, or array');
                } else {
                    // Merge the default settings with the provided settings
                    $value += self::$defaultRedirect;
                }

                $config['redirect'] = $value;
                $emitter->attach($this->redirectPlugin);
                break;

            case 'decode_content':

                if ($value === false) {
                    continue;
                }

                $config['decode_content'] = true;
                if ($value !== true) {
                    $request->setHeader('Accept-Encoding', $value);
                }
                break;

            case 'headers':

                if (!is_array($value)) {
                    throw new Iae('header value must be an array');
                }
                foreach ($value as $k => $v) {
                    $request->setHeader($k, $v);
                }
                break;

            case 'exceptions':

                if ($value === true) {
                    $emitter->attach($this->errorPlugin);
                }
                break;

            case 'body':

                if (is_array($value)) {
                    $this->addPostData($request, $value);
                } elseif ($value !== null) {
                    $request->setBody(Stream::factory($value));
                }
                break;

            case 'auth':

                if (!$value) {
                    continue;
                }

                if (is_array($value)) {
                    $type = isset($value[2]) ? strtolower($value[2]) : 'basic';
                } else {
                    $type = strtolower($value);
                }

                $config['auth'] = $value;

                if ($type == 'basic') {
                    $request->setHeader(
                        'Authorization',
                        'Basic ' . base64_encode("$value[0]:$value[1]")
                    );
                } elseif ($type == 'digest') {
                    // @todo: Do not rely on curl
                    $config->setPath('curl/' . CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
                    $config->setPath('curl/' . CURLOPT_USERPWD, "$value[0]:$value[1]");
                }
                break;

            case 'query':

                if ($value instanceof Query) {
                    $original = $request->getQuery();
                    // Do not overwrite existing query string variables by
                    // overwriting the object with the query string data passed
                    // in the URL
                    $value->overwriteWith($original->toArray());
                    $request->setQuery($value);
                } elseif (is_array($value)) {
                    // Do not overwrite existing query string variables
                    $query = $request->getQuery();
                    foreach ($value as $k => $v) {
                        if (!isset($query[$k])) {
                            $query[$k] = $v;
                        }
                    }
                } else {
                    throw new Iae('query must be an array or Query object');
                }
                break;

            case 'cookies':

                if ($value === true) {
                    static $cookie = null;
                    if (!$cookie) {
                        $cookie = new Cookie();
                    }
                    $emitter->attach($cookie);
                } elseif (is_array($value)) {
                    $emitter->attach(
                        new Cookie(CookieJar::fromArray($value, $request->getHost()))
                    );
                } elseif ($value instanceof CookieJarInterface) {
                    $emitter->attach(new Cookie($value));
                } elseif ($value !== false) {
                    throw new Iae('cookies must be an array, true, or CookieJarInterface');
                }
                break;

            case 'events':

                if (!is_array($value)) {
                    throw new Iae('events must be an array');
                }

                $this->attachListeners($request,
                    $this->prepareListeners(
                        $value,
                        ['before', 'complete', 'error', 'progress', 'end']
                    )
                );
                break;

            case 'subscribers':

                if (!is_array($value)) {
                    throw new Iae('subscribers must be an array');
                }

                foreach ($value as $subscribers) {
                    $emitter->attach($subscribers);
                }
                break;

            case 'json':

                $request->setBody(Stream::factory(json_encode($value)));
                if (!$request->hasHeader('Content-Type')) {
                    $request->setHeader('Content-Type', 'application/json');
                }
                break;

            default:

                // Check for custom handler functions.
                if (isset($this->customOptions[$key])) {
                    $fn = $this->customOptions[$key];
                    $fn($request, $value);
                    continue;
                }

                throw new Iae("No method can handle the {$key} config key");
            }
        }
    }
}
