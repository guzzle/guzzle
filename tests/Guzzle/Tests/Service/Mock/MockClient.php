<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Mock;

use Guzzle\Service\Client;

/**
 * Mock Guzzle Service
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle username required="true" doc="API username"
 * @guzzle password required="true" doc="API password"
 * @guzzle subdomain required="true" doc="Project subdomain"
 * @guzzle api_version required="true" default="v1" doc="API version"
 * @guzzle protocol required="true" default="http" doc="HTTP protocol (http or https)"
 * @guzzle base_url required="true" default="{{ protocol }}://127.0.0.1:8124/{{ api_version }}/{{ subdomain }}" doc="Unfuddle API base URL"
 */
class MockClient extends Client
{
}