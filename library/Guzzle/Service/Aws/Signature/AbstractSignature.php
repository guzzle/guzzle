<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Signature;

use Guzzle\Service\Aws\AwsException;

/**
 * Abstract class to construct an AWS signature.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractSignature
{
    /**
     * @var string AWS Secret Access Key.
     */
    protected $awsSecretAccessKey = '';

    /**
     * @var string AWS Access Key ID
     */
    protected $awsAccessKeyId = '';

    /**
     * @var string PHP named Hashing algorithm.
     */
    protected $phpHashingAlgorithm;

    /**
     * @var string AWS named Hashing algorithm name.
     */
    protected $awsHashingAlgorithm;
    
    /**
     * @var string The SignatureVersion parameter
     */
    protected $signatureVersion;

    /**
     * Constructor
     *
     * @param string $awsAccessKeyId Your AWS Access Key ID
     * @param string $awsSecretAccessKey Your AWS Secret Access Key.
     *
     * @throws Guzzle\Service\Aws\AwsException if an AWS Secret Access Key is not passed.
     */
    public function __construct($awsAccessKeyId, $awsSecretAccessKey)
    {
        if (!$awsAccessKeyId) {
            throw new AwsException('An AWS Access Key ID must be passed to ' . __METHOD__);
        }

        if (!$awsSecretAccessKey) {
            throw new AwsException('An AWS Secret Access Key must be passed to ' . __METHOD__);
        }
        
        $this->awsSecretAccessKey = $awsSecretAccessKey;
        $this->awsAccessKeyId = $awsAccessKeyId;
    }

    /**
     * Get the Access Key ID
     *
     * @return string
     */
    public function getAccessKeyId()
    {
        return $this->awsAccessKeyId;
    }

    /**
     * Retrieve the AWS named hashing algorithm.
     *
     * @return string Returns the AWS named hashing algorithm used to sign requests.
     */
    public function getAwsHashingAlgorithm()
    {
        return $this->awsHashingAlgorithm;
    }

    /**
     * Retrieve the PHP named hashing algorithm.
     *
     * @return string Returns the PHP named hashing algorithm used to sign requests.
     */
    public function getPhpHashingAlgorithm()
    {
        return $this->phpHashingAlgorithm;
    }

    /**
     * Get the Secret Access Key
     *
     * @return string
     */
    public function getSecretAccessKey()
    {
        return $this->awsSecretAccessKey;
    }

    /**
     * Get the signature version
     *
     * @return string
     */
    public function getVersion()
    {
        return $this->signatureVersion;
    }

    /**
     * Sign a string using an AWS Secret Access Key.
     *
     * @param string $stringToSign String to sign.
     *
     * @return string Returns a signed string.
     */
    public function signString($stringToSign)
    {
        return base64_encode(hash_hmac($this->phpHashingAlgorithm, $stringToSign, $this->awsSecretAccessKey, true));
    }
    
    /**
     * Calculate a string to sign based on an associative array of request
     * parameters and options.
     *
     * @param array $request Associative array of request parameters
     * @param array $options Array of options.  See child classes for possible keys.
     *
     * @return string Returns a calculated string to sign.
     */
    abstract public function calculateStringToSign(array $request, array $options = null);
}