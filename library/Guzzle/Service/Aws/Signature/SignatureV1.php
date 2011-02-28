<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Signature;

/**
 * Amazon Web Services Signature Version 1
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SignatureV1 extends AbstractSignature
{
    /**
     * {@inheritdoc}
     */
    protected $phpHashingAlgorithm = 'sha1';

    /**
     * {@inheritdoc}
     */
    protected $awsHashingAlgorithm = 'HmacSHA1';

    /**
     * {@inheritdoc}
     */
    protected $signatureVersion = '1';

    /**
     * {@inheritdoc}
     */
    public function calculateStringToSign(array $request, array $options = null)
    {
        if (!count($request)) {
            return '';
        }

        if (is_null($options)) {
            $options = array();
        }

        $ignore = (array_key_exists('ignore', $options)) ? $options['ignore'] : 'awsSignature';
        $sortMethod = (array_key_exists('sort_method', $options)) ? $options['sort_method'] : 'strcasecmp';
        $sigString = '';
        uksort($request, $sortMethod);

        foreach ($request as $k => $v) {
            if ($k && $v && strcmp($k, $ignore)) {
                $sigString = $sigString . $k . $v;
            }
        }

        return $sigString;
    }
}