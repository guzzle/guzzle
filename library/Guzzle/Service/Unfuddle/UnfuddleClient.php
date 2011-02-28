<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle;

use Guzzle\Service\Client;

/**
 * Client for interacting with the Unfuddle webservice
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle username required="true" doc="API username"
 * @guzzle password required="true" doc="API password"
 * @guzzle subdomain required="true" doc="Unfuddle project subdomain"
 * @guzzle api_version required="true" default="v1" doc="API version"
 * @guzzle protocol required="true" default="https" doc="HTTP protocol (http or https)"
 * @guzzle base_url required="true" default="{{ protocol }}://{{ subdomain }}.unfuddle.com/api/{{ api_version }}/" doc="Unfuddle API base URL"
 */
class UnfuddleClient extends Client
{
    /**
     * {@inheritdoc}
     *
     * Configures a request for use with Unfuddle
     */
    public function getRequest($httpMethod, $headers = null, $body = null)
    {
        $request = parent::getRequest($httpMethod, $headers, $body);
        $request->setHeader('Accept', 'application/xml')
                ->setAuth($this->config->get('username'), $this->config->get('password'));

        // Configure the querystring to use a path based query string
        $request->getQuery()->setPrefix('')->setFieldSeparator('/')->setValueSeparator('/');

        return $request;
    }
}