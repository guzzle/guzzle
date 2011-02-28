<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws;

use Guzzle\Common\Subject\SubjectMediator;
use Guzzle\Http\Plugin\AbstractPlugin;
use Guzzle\Service\Aws\Filter\AddRequiredQueryStringFilter;
use Guzzle\Service\Aws\Filter\QueryStringSignatureFilter;

class QueryStringAuthPlugin extends AbstractPlugin
{
    /**
     * @var Signature\AbstractSignature
     */
    private $signature;

    /**
     * @var string API version of the service
     */
    private $apiVersion;

    /**
     * Construct a new request signing plugin
     *
     * @param Signature\AbstractSignature $signature Signature object used to sign requests
     * @param string $apiVersion API version of the service
     */
    public function __construct(Signature\AbstractSignature $signature, $apiVersion)
    {
        $this->signature = $signature;
        $this->apiVersion = $apiVersion;
    }

    /**
     * Get the signature object used to sign requests
     *
     * @return Signature\AbstractSignature
     */
    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * Get the API version of the service
     *
     * @return string
     */
    public function getApiVersion()
    {
        return $this->apiVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function update(SubjectMediator $subject)
    {
        if ($subject->getState() == 'request.create') {
            $subject->getContext()->getPrepareChain()
                ->addFilter(new AddRequiredQueryStringFilter(array(
                    'signature' => $this->signature,
                    'version' => $this->apiVersion
                )))
                ->addFilter(new QueryStringSignatureFilter(array(
                    'signature' => $this->signature
                )));
        }
    }
}