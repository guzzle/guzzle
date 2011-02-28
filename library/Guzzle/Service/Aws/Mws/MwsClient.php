<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws;

use Guzzle\Service\Client;

// @codeCoverageIgnoreStart
/**
 * Client for Amazon Marketplace Web Service
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 * @see https://developer.amazonservices.com/
 *
 * @guzzle cache.key_filter static="query=Timestamp, Signature"
 * @guzzle merchant_id required="true" doc="AWS Merchant ID"
 * @guzzle marketplace_id required="true" doc="AWS Marketplace ID"
 * @guzzle access_key_id required="true" doc="AWS Access Key ID"
 * @guzzle secret_access_key required="true" doc="AWS Secret Access Key"
 * @guzzle application_name required="true" doc="Application name"
 * @guzzle application_version required="true" doc="Application version"
 * @guzzle base_url required="true" default="{{ protocol }}://{{ region }}/" doc="SQS service base URL"
 */
class MwsClient extends Client
{
}
// @codeCoverageIgnoreEnd