<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb;

use Guzzle\Http\QueryString;
use Guzzle\Service\Aws\AbstractClient;

/**
 * Client for interacting with Amazon SimpleDb
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle access_key_id required="true" doc="AWS Access Key ID"
 * @guzzle secret_access_key required="true" doc="AWS Secret Access Key"
 * @guzzle protocol required="true" default="http" doc="Protocol to use with requests (http or https)"
 * @guzzle region required="true" default="sdb.amazonaws.com" doc="Amazon SimpleDB Region endpoint"
 * @guzzle base_url required="true" default="{{ protocol }}://{{ region }}/" doc="SimpleDB service base URL"
 *
 * @guzzle cache.key_filter static="query=Timestamp, Signature"
 */
class SimpleDbClient extends AbstractClient
{
}