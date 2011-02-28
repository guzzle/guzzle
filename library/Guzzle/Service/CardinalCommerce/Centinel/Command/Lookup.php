<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Common\Inflector;
use Guzzle\Common\Collection;

/**
 * The Lookup Message is responsible for initiating the Payer Authentication.
 * The integration point for the Lookup Message is immediately following the
 * capture of the payment informa- tion, including the final order amount and
 * the credit card information.
 *
 * Note: Authentication is REQUIRED to take place prior to authorization. Data
 * resulting from the authentication process MUST be represented on the
 * authorization transaction to ensure the merchant will be provided the
 * benefits from the program.
 *
 * Lookup Message requires transaction specific data elements to be formatted
 * on the request message. Please refer to the Message API section for the
 * complete list of required message elements.  The response message is
 * returned from the Centinel MAPS, and the merchant invokes the Thin Client
 * API to reference the response values. In the event that the Enrolled value
 * is Y the ACSUrl element will contain a fully qualified URL that the consumer
 * should be redirected to for authentication with the Card Issuer.
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle msg_type static="cmpi_lookup"
 * @guzzle transaction_type required="true"
 */
class Lookup extends Txn
{
    /**
     * @var int Number of items in lookup command
     */
    protected $itemCount = 0;

    /**
     * Unformatted Total sale amount without any decimalization.  For example,
     * $100.00 = 10000, $123.67 = 12367, $.99 = 99
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setAmount($value)
    {
        return $this->set('amount', $this->convertCurrency($value));
    }

    /**
     * Unformatted shipping amount without any decimalization. For example,
     * $100.00 = 10000, $123.67 = 12367, $.99 = 99
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setShippingAmount($value)
    {
        return $this->set('shipping_amount', $this->convertCurrency($value));
    }

    /**
     * Unformatted tax amount without any decimalization. For example,
     * $100.00 = 10000, $123.67 = 12367, $.99 = 99
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setTaxAmount($value)
    {
        return $this->set('tax_amount', $this->convertCurrency($value));
    }

    /**
     * 3 digit numeric, ISO 4217 currency code for the sale amount.
     *  For example: USD - 840, EUR - 978, JPY - 392, CAD - 124, GBP - 826
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setCurrencyCode($value)
    {
        return $this->set('currency_code', $value);
    }

    /**
     * Card Number Expiration Month, formatted MM. Example: 01 (Jan.)
     *
     * @param string $cardNumber Credit card number
     * @param string $cardExpMonth Credit card expiration month formatted MM (01 - Jan)
     * @param string $cardExpYear Credit card expiration year (e.g. 2012)
     *
     * @return Lookup
     */
    public function setCreditCard($cardNumber, $cardExpMonth, $cardExpYear)
    {
        return $this->set('card_number', $cardNumber)
                    ->set('card_exp_month', sprintf('%02d', $cardExpMonth))
                    ->set('card_exp_year', sprintf('%02d', $cardExpYear));
    }

    /**
     * Order Number or transaction identifier from the Merchant eCommerce
     * website.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setOrderNumber($value)
    {
        return $this->set('order_number', $value);
    }

    /**
     * Brief description of items purchased.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setOrderDescription($value)
    {
        return $this->set('order_description', $value);
    }

    /**
     * Merchant specified data that will be returned on the response.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setMerchantData($value)
    {
        return $this->set('merchant_data', $value);
    }

    /**
     * Merchant specified transaction identifier that will be returned on the
     * response.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setMerchantReferenceNumber($value)
    {
        return $this->set('merchant_reference_number', $value);
    }

    /**
     * The exact content of the HTTP user-agent header.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setUserAgent($value)
    {
        return $this->set('user_agent', $value);
    }

    /**
     * The exact content of the HTTP accept header.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setBrowserHeader($value)
    {
        return $this->set('browser_header', $value);
    }

    /**
     * Set recurring data on the lookup
     *
     * @param $recurringFreq Integer value indicating the minimum number of
     *      days between recurring authorizations. A frequency of monthly is
     *      indicated by the value 28.
     * @param string $recurringEnd The date after which no further recurring
     *      authorizations should be performed. Format YYYYMMDD.
     *
     * @return Lookup
     */
    public function setRecurring($recurringFreq, $recurringEnd)
    {
        return $this->set('recurring', 'Y')
                    ->set('recurring_frequency', $recurringFreq)
                    ->set('recurring_end', $recurringEnd);
    }

    /**
     * Specifies the order channel where the transaction was initiated.
     * 
     *  - MARK - Transaction initiated from the payment page.
     *  - CART - Transaction initiated from the cart page.
     *  - CALLCENTER - Transaction initiated from the call center.
     *  - WIDGET - Transaction initiated from the widget.
     *  - PRODUCT - Transaction initiated from the product.
     *  - 1CLICK - Transaction initiated from 1 Click.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setOrderChannel($value)
    {
        return $this->set('order_channel', $value);
    }

    /**
     * Specifies the product code for the transaction.
     * 
     *  - PHY - Physical Delivery
     *  - CNC - Cash and Carry
     *  - DIG - Digital Good
     *  - SVC - Service
     *  - TBD - Other
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setProductCode($value)
    {
        return $this->set('product_code', $value);
    }

    /**
     * Transaction mode identifier. Identifies the channel the transaction
     * originates from:
     *
     * - M - Mail Order/Telephone Order
     * - R - Retail
     * - S - eCommerce
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setTransactionMode($value)
    {
        return $this->set('transaction_mode', $value);
    }

    /**
     * An integer value greater than 1 indicating the maximum number of
     * permitted authorizations for installment payments. Must be included if 
     * the Merchant and cardholder have agreed to installment payments.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setInstallment($value)
    {
        return $this->set('installment', $value);
    }

    /**
     * Required only when processing within certain Visa Regions. The value is
     * used to facilitate Merchant Authentication File (MAF) authentication
     * processing. Note that if this value is passed it will override the
     * password value configured on the Merchant's payment initiative
     * configuration.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setAquirerPassword($value)
    {
        return $this->set('aquirer_password', $value);
    }

    /**
     * Consumer's email address.
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setEmail($value)
    {
        return $this->set('email', $value);
    }

    /**
     * The IP Address of the Consumer. Format NNN.NNN.NNN.NNN
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setIpAddress($value)
    {
        return $this->set('ip_address', $value);
    }

    /**
     * Add an item to the request
     * 
     * @param array $data Data to set on the product, including:
     *      sku => SKU of the product
     *      price => Price paid for product
     *      qty => Quantity ordered
     *      name (optional) => Name of the product
     *      description (optional) => Description of the product
     *      ... => Any other vendor specific data to add with the item.  Use a
     *          %s in the key of the item in the place that the item number count
     *          should be inserted (e.g. Item_ShipMethod_%s_123, Item_Promotion_%s).
     *          If the key does not map to the correct XML node name  using a
     *          simple CamelCase conversion, then the XML node name must be used
     *          as the key instead of the snake_case representation.
     *
     * @return Lookup
     * @throws InvalidArgumentException if sku, price, or qty are missing
     */
    public function addProduct(array $data)
    {
        $data = new Collection($data);

        // only add if the required fields are set
        if (!$data->get('sku') || !$data->hasKey('price') || !$data->get('qty')) {
            throw new \InvalidArgumentException('sku, qty, and price are required for each product');
        }
            
        $i = ++$this->itemCount;
        $this->set("Item_SKU_{$i}", $data->get('sku'))
             ->set("Item_Quantity_{$i}", $data->get('qty'))
             ->set("Item_Price_{$i}", $this->convertCurrency($data->get('price')));

        if ($data->get('name')) {
            $this->set("Item_Name_{$i}", $data->get('name'));
        }

        if ($data->get('description')) {
            $this->set("Item_Desc_{$i}", $data->get('description'));
        }

        foreach ($data->filter(function($key, $value) {
            return !in_array($key, array('sku', 'qty', 'price', 'name', 'description'));
        }) as $name => $value) {
            $this->set(sprintf($name, $i), $value);
        }

        return $this;
    }
}