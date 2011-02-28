<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

/**
 * Settles a previously authorized transaction and obtains payment for the full
 * amount even if it is a partial settlement.
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle msg_type static="cmpi_capture"
 * @guzzle currency_code default="840" required="true"
 * @guzzle order_id required="true"
 */
class Capture extends Txn
{
    /**
     * @var int Number of items in the request
     */
    protected $items = 0;

    /**
     * Centinel generated order identifier. Represents the value returned on the
     * Lookup Response message.
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setOrderId($value)
    {
        return $this->set('order_id', $value);
    }

    /**
     * Unformatted total transaction amount without any decimalization.
     *
     * For example, $100.00 = 10000, $123.67 = 12367, $.99 = 99.  NOTE - Amount is
     * only required when line item captures are not used.
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setAmount($value)
    {
        return $this->set('amount', $this->convertCurrency($value));
    }

    /**
     * 3 digit numeric, ISO 4217 currency code for the transaction amount.
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setCurrencyCode($value)
    {
        return $this->set('currency_code', $value);
    }

    /**
     * Contains the name of the company responsible for shipping the item. Valid
     * values for this tag are:
     * UPS, USPS, FedEx, DHL, Fastway, GLS, GO!, Hermes Logistik Grupp, Royal Mail,
     * Parcelforce, City Link, TNT, Target, SagawaExpress, NipponExpress,
     * YamatoTransport
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setCarrier($value)
    {
        return $this->set('carried', $value);
    }

    /**
     * Contains the ship method name.
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setShipMethodName($value)
    {
        return $this->set('ship_method_name', $value);
    }

    /**
     * Contains the shipper's tracking number that is associated with an order.
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setTrackingNumber($value)
    {
        return $this->set('tracking_number', $value);
    }

    /**
     * Brief description of items purchased.
     *
     * @param string $value Value to set
     *
     * @return Capture
     */
    public function setOrderDescription($value)
    {
        return $this->set('order_description', $value);
    }

    /**
     * Add a line item product to the request
     *
     * NOTE - if line items are not passed then all items will be marked as
     * shipped
     *
     * @param string $sku Product SKU shipped
     * @param int $qty Product QTY shipped
     *
     * @return Capture
     */
    public function addProduct($sku, $qty)
    {
        $i = ++$this->itemCount;
        $this->set("Item_SKU_{$i}", $sku)
             ->set("Item_Quantity_{$i}", $qty);

        return $this;
    }
}