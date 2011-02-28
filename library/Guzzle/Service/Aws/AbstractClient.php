<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws;

use Guzzle\Common\Subject\Observer;
use Guzzle\Service\Client;

/**
 * Abstract AWS Client
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractClient extends Client
{
    /**
     * Get the AWS Access Key ID
     *
     * @return string
     */
    public function getAccessKeyId()
    {
        return $this->config->get('access_key_id');
    }

    /**
     * Get the AWS Secret Access Key
     *
     * @return string
     */
    public function getSecretAccessKey()
    {
        return $this->config->get('secret_access_key');
    }
}