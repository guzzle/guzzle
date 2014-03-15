<?php

namespace Guzzle\Plugin\Oauth;

use Guzzle\Common\Event;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\QueryString;
use Guzzle\Http\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * OAuth signing plugin
 *
 * Most of code comes from HWIOAuthBundle
 * @link https://github.com/hwi/HWIOAuthBundle
 *
 * Authors of original code:
 * @author Alexander <iam.asm89@gmail.com>
 * @author Joseph Bielawski <stloyd@gmail.com>
 * @author Francisco Facioni <fran6co@gmail.com>
 *
 * @link http://oauth.net/core/1.0/#rfc.section.9.1.1
 */
class OauthPlugin implements EventSubscriberInterface
{
    /**
     * Consumer request method constants. See http://oauth.net/core/1.0/#consumer_req_param
     */
    const REQUEST_METHOD_HEADER = 'header';
    const REQUEST_METHOD_QUERY  = 'query';

    const SIGNATURE_METHOD_HMAC      = 'HMAC-SHA1';
    const SIGNATURE_METHOD_RSA       = 'RSA-SHA1';
    const SIGNATURE_METHOD_PLAINTEXT = 'PLAINTEXT';

    /** @var Collection Configuration settings */
    protected $config;

    /**
     * Create a new OAuth 1.0 plugin
     *
     * @param array $config Configuration array containing these parameters:
     *     - string 'request_method'       Consumer request method. Use the class constants.
     *     - string 'consumer_key'         Consumer key
     *     - string 'consumer_secret'      Consumer secret
     *     - string 'token'                Token
     *     - string 'token_secret'         Token secret
     *     - string 'verifier'             OAuth verifier.
     *     - string 'version'              OAuth version. Defaults to 1.0
     *     - string 'realm'                OAuth realm.
     *     - string 'signature_method'     Custom signature method
     */
    public function __construct($config)
    {
        $this->config = Collection::fromConfig($config, array(
            'version'          => '1.0',
            'request_method'   => self::REQUEST_METHOD_HEADER,
            'consumer_key'     => 'anonymous',
            'consumer_secret'  => 'anonymous',
            'signature_method' => self::SIGNATURE_METHOD_HMAC,
        ), array(
            'signature_method', 'version', 'consumer_key', 'consumer_secret'
        ));
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.before_send' => array('onRequestBeforeSend', -1000)
        );
    }

    /**
     * Request before-send event handler
     *
     * @param Event $event Event received
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    public function onRequestBeforeSend(Event $event)
    {
        /* @var $request RequestInterface */
        $request = $event['request'];

        $timestamp = $this->getTimestamp($event);
        $nonce     = $this->generateNonce($request);
        $authorizationParams = $this->getOauthParams($timestamp, $nonce);
        $authorizationParams['oauth_signature'] = $this->getSignature($request, $timestamp, $nonce);

        switch ($this->config['request_method']) {
            case self::REQUEST_METHOD_HEADER:
                list($header, $value) = $this->buildAuthorizationHeader($authorizationParams);

                $request->setHeader($header, $value);
                break;
            case self::REQUEST_METHOD_QUERY:
                foreach ($authorizationParams as $key => $value) {
                    $request->getQuery()->set($key, $value);
                }
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Invalid consumer method "%s"',
                    $this->config['request_method']
                ));
        }

        return $authorizationParams;
    }

    /**
     * Calculate signature for request
     *
     * @param RequestInterface $request   Request to generate a signature for
     * @param integer          $timestamp Timestamp to use for nonce
     * @param string           $nonce
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function getSignature(RequestInterface $request, $timestamp, $nonce)
    {
        $parameters = $this->getOauthParams($timestamp, $nonce);

        // Remove oauth_signature if present
        // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
        if ($parameters->hasKey('oauth_signature')) {
            $parameters->remove('oauth_signature');
        }

        $parameters = $parameters->toArray();

        // Parse & add query params as base string parameters if they exists
        $urlQuery = Url::factory($request->getUrl())->getQuery();
        if (null !== $urlQuery) {
            parse_str($urlQuery, $queryParams);
            $parameters += $queryParams;
        }

        // Remove query params from URL
        // Ref: Spec: 9.1.2
        $url = Url::factory($request->getUrl())->setQuery('')->setFragment(null);

        // Parameters are sorted by name, using lexicographical byte value ordering.
        // Ref: Spec: 9.1.1 (1)
        $parameters = $this->prepareParameters($parameters);

        // http_build_query should use RFC3986
        $parts = array(
            // HTTP method name must be uppercase
            // Ref: Spec: 9.1.3 (1)
            $request->getMethod(),
            rawurlencode($url),
            rawurlencode(str_replace(array('%7E', '+'), array('~', '%20'), http_build_query($parameters, '', '&'))),
        );

        $baseString = implode('&', $parts);

        switch ($this->config['signature_method']) {
            case self::SIGNATURE_METHOD_HMAC:
                $keyParts = array(
                    rawurlencode($this->config['consumer_key']),
                    rawurlencode($this->config['consumer_secret']),
                );

                $signature = hash_hmac('sha1', $baseString, implode('&', $keyParts), true);
                break;

            case self::SIGNATURE_METHOD_RSA:
                if (!function_exists('openssl_pkey_get_private')) {
                    throw new \RuntimeException('RSA-SHA1 signature method requires the OpenSSL extension.');
                }

                $privateKey = openssl_pkey_get_private(file_get_contents($this->config['consumer_secret']), $this->config['consumer_secret']);
                $signature  = false;

                openssl_sign($baseString, $signature, $privateKey);
                openssl_free_key($privateKey);
                break;

            case self::SIGNATURE_METHOD_PLAINTEXT:
                $signature = $baseString;
                break;

            default:
                throw new \RuntimeException(sprintf('Unknown signature method selected %s.', $this->config['signature_method']));
        }

        return base64_encode($signature);
    }

    /**
     * Get the oauth parameters as named by the oauth spec
     *
     * @param string $timestamp Timestamp to use for nonce
     * @param string $nonce
     *
     * @return Collection
     */
    protected function getOauthParams($timestamp, $nonce)
    {
        $params = new Collection(array(
            'oauth_consumer_key'     => $this->config['consumer_key'],
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => $this->config['signature_method'],
            'oauth_timestamp'        => $timestamp,
        ));

        // Optional parameters should not be set if they have not been set in the config as
        // the parameter may be considered invalid by the Oauth service.
        $optionalParams = array(
            'token'     => 'oauth_token',
            'verifier'  => 'oauth_verifier',
            'version'   => 'oauth_version'
        );

        foreach ($optionalParams as $optionName => $oauthName) {
            if (isset($this->config[$optionName])) {
                $params[$oauthName] = $this->config[$optionName];
            }
        }

        return $params;
    }

    /**
     * Returns a Nonce Based on the unique id and URL. This will allow for multiple requests in parallel with the same
     * exact timestamp to use separate nonce's.
     *
     * @param RequestInterface $request Request to generate a nonce for
     *
     * @return string
     */
    public function generateNonce(RequestInterface $request)
    {
        return sha1(uniqid('', true).$request->getUrl());
    }

    /**
     * Gets timestamp from event or create new timestamp
     *
     * @param Event $event Event containing contextual information
     *
     * @return integer
     */
    public function getTimestamp(Event $event)
    {
       return $event['timestamp'] ?: time();
    }

    /**
     * Convert booleans to strings, removed unset parameters, and sorts the array
     *
     * @param array $data Data array
     *
     * @return array
     */
    protected function prepareParameters($data)
    {
        // Parameters are sorted by name, using lexicographical byte value ordering.
        // Ref: Spec: 9.1.1 (1)
        uksort($data, 'strcmp');

        foreach ($data as $key => &$value) {
            switch (gettype($value)) {
                case 'NULL':
                    unset($data[$key]);
                    break;
                case 'array':
                    $data[$key] = self::prepareParameters($value);
                    break;
                case 'boolean':
                    $data[$key] = $value ? 'true' : 'false';
                    break;
            }
        }

        return $data;
    }

    /**
     * Builds the Authorization header for a request
     *
     * @param Collection $authorizationParams Associative array of authorization parameters
     *
     * @return array
     */
    private function buildAuthorizationHeader(Collection $authorizationParams)
    {
        foreach ($authorizationParams as $key => $value) {
            $authorizationParams[$key] = sprintf('%s="%s"', $key, rawurlencode($value));
        }

        if (!$this->config['realm']) {
            array_unshift($parameters, sprintf('realm="%s"', rawurlencode($this->config['realm'])));
        }

        return array('Authorization', 'OAuth ' . implode(', ', $authorizationParams->toArray()));
    }
}
