<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs;

use Guzzle\Service\Aws\AbstractClient;

/**
 * Client for interacting with Amazon SQS
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle access_key_id required="true" doc="AWS Access Key ID"
 * @guzzle secret_access_key required="true" doc="AWS Secret Access Key"
 * @guzzle protocol required="true" default="http" doc="Protocol to use with requests (http or https)"
 * @guzzle region required="true" default="sqs.us-east-1.amazonaws.com" doc="Amazon SQS Region endpoint"
 * @guzzle base_url required="true" default="{{ protocol }}://{{ region }}/" doc="SQS service base URL"
 * @guzzle version required="true" default="2009-02-01"
 *
 * @guzzle cache.key_filter static="query=Timestamp, Signature"
 */
class SqsClient extends AbstractClient
{
}