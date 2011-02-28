<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel;

use Guzzle\Service\Client;
use Guzzle\Service\CardinalCommerce\Centinel\Command\DefaultCommand;

/**
 * Client for interacting with Cardinal Commerce Centinal API.
 *
 * Add the following to your services XML file to use Cardinal Commerce:
 *
 *     <client name="test.centinel" builder="Guzzle.Service.Builder.DefaultBuilder" class="Guzzle.Service.CardinalCommerce.Centinel.CentinelClient">
 *         <param name="password" value="CHANGE_ME" />
 *         <param name="processor_id" value="CHANGE_ME" />
 *         <param name="merchant_id" value="CHANGE_ME" />
 *     </client>
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle processor_id required="true" doc="Processor ID assigned by Cardinal Commerce"
 * @guzzle merchant_id required="true" doc="Merchant ID assigned by Cardinal Commerce"
 * @guzzle password required="true" doc="Transaction password defined by the merchant within the merchant profile. Note that this is NOT your user password."
 * @guzzle version required="true" default="1.7" doc="API version"
 * @guzzle base_url required="true" default="https://centineltest.cardinalcommerce.com/maps/txns.asp" doc="Cardinal Commerce API endpoint"
 */
class CentinelClient extends Client
{
    const TYPE_CREDIT_CARD = 'C';
    const TYPE_AMAZON = 'Ac';

    const CURRENCY_US = 840;
    const CURRENCY_EUR = 978;
    const CURRENCY_JPY = 392;
    const CURRENCY_CAD = 123;
    const CURRENCY_GBP = 826;

    const CHAN_MARK = 'MARK';
    const CHAN_CART = 'CART';
    const CHAN_CALL = 'CALLCENTER';
    const CHAN_WIDGET = 'WIDGET';
    const CHAN_PRODUCT = 'PRODUCT';
    const CHAN_1CLICK = '1CLICK';

    const CODE_PHY = 'PHY'; // Physical deliver
    const CODE_CNC = 'CNC'; // Cash and Carry
    const CODE_DIG = 'DIG'; // Digital Good
    const CODE_SVC = 'SVC'; // Service
    const CODE_TBD = 'TBD'; // Other

    const MODE_MOTO = 'M';
    const MODE_RETAIL = 'R';
    const MODE_ECOMMERCE = 'S';

    /**
     * Generate a Centinel payload using an array of data
     *
     * @param array $args Arguments for the payload
     *
     * @return string
     */
    public function generatePayload(array $args)
    {
        $payload = '';
        $first = true;
        foreach ($args as $name => $value) {
            if ($name == 'TransactionPwd') {
                continue;
            }
            if (!$first) {
                $payload .= '&';
            }
            $payload .= $name . '=' . urlencode($value);
            $first = false;
        }

        return $payload . '&Hash=' . sha1($payload . $this->config->get('password'));
    }


    /**
     * Returns an array of payload data
     *
     * @param string $payload Payload to unpack
     *
     * @return array
     */
    public function unpackPayload($payload)
    {
        $response = array();

        if (!$payload) {
            return $response;
        }

        $parts = explode('&', $payload);
        $hashPart =  explode('=', $parts[count($parts) - 1]);
        $origPayload = preg_replace('#\&' . preg_quote($parts[count($parts) - 1]) . '#', '', $payload) . $this->config->get('password');
        $payloadHash = $hashPart[1];
        $payloadCalcHash = sha1($origPayload);

        if (strcmp($payloadCalcHash, $payloadHash)) {
            throw new InvalidPayloadException('The payload we calculated did not match');
        }

        for ($i = 0, $total = count($parts); $i < $total; $i++) {
            list($key, $value) = explode('=', $parts[$i]);
            $value = urldecode($value);
            if (!isset($response[$key])) {
                $response[$key] = '';
            }
            $response[$key] .= (($response[$key]) ? ', ' : '') . $value;
        }

        return $response;
    }
}