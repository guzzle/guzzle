<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\Signature\SignatureV2;
use Guzzle\Service\Aws\AbstractBuilder;
use Guzzle\Service\Aws\Signature\AbstractSignature;
use Guzzle\Service\Aws\QueryStringAuthPlugin;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\DescriptionBuilder\ConcreteDescriptionBuilder;

/**
 * Builder object to build an Amazon SQS client
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SqsBuilder extends AbstractBuilder
{
    const REGION_US_EAST_1 = 'sqs.us-east-1.amazonaws.com'; // Endpoint located in the US-East (Northern Virginia) Region
    const REGION_US_WEST_1 = 'sqs.us-west-1.amazonaws.com'; // Endpoint located in the US-West (Northern California) Region
    const REGION_EU_WEST_1 = 'sqs.eu-west-1.amazonaws.com'; // Endpoint located in the EU (Ireland) Region
    const REGION_AP_SOUTHEAST_1 = 'sqs.ap-southeast-1.amazonaws.com'; // Endpoint located in the Asia Pacific (Singapore) Region

    /**
     * @var AbstractSignature
     */
    protected $signature;

    /**
     * Build the Amazon SQS client
     *
     * @return SqsClient
     */
    public function build()
    {
        if (!$this->signature) {
            $this->signature = new SignatureV2($this->config->get('access_key_id'), $this->config->get('secret_access_key'));
        }

        $builder = new ConcreteDescriptionBuilder($this->getClass(), $this->config->get('base_url'));
        $serviceDescription = $builder->build();
        $commandFactory = new ConcreteCommandFactory($serviceDescription);

        $client = new SqsClient($this->config, $serviceDescription, $commandFactory);
        $client->attachPlugin(new QueryStringAuthPlugin($this->signature, $this->config->get('version', '2009-02-01')));

        return $client;
    }

    /**
     * {@inheritdoc}
     */
    public function getClass()
    {
        return 'Guzzle\\Service\\Aws\\Sqs\\SqsClient';
    }
}