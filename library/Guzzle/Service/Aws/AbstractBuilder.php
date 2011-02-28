<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws;

use Guzzle\Common\Collection;
use Guzzle\Http\Message\Request;
use Guzzle\Service\Builder\AbstractBuilder as ABuilder;

/**
 * Abstract builder to build an Amazon Web Services client
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractBuilder extends ABuilder
{
    /**
     * @var Signature\AbstractSignature
     */
    protected $signature;

    /**
     * Specify your AWS account access credentials to enable request
     * authentication.
     *
     * If no credentials are specified, requests will be made
     * anonomously.
     *
     * @param string $accessKeyId Your AWS Access Key ID
     * @param string $secretAccessKey Your AWS Secret Access Key ID
     *
     * @return AbstractBuilder
     */
    public function setAuthentication($accessKeyId, $secretAccessKey)
    {
        $this->config->set('access_key_id', $accessKeyId)
                     ->set('secret_access_key', $secretAccessKey);
        
        return $this;
    }

    /**
     * Set the signature object that will be used to sign requests for the
     * client
     *
     * @param Signature\AbstractSignature $signature Signature object to set
     *
     * @return AbstractBuilder
     */
    public function setSignature(Signature\AbstractSignature $signature)
    {
        $this->signature = $signature;
        
        return $this;
    }

    /**
     * Set the service version.  A default version will be used if a version
     * is not specified.
     *
     * @param string $version The service version (e.g. 2009-04-15)
     *
     * @return AbstractBuilder
     */
    public function setVersion($version)
    {
        $this->config->set('version', $version);
        
        return $this;
    }
}