<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Http\EntityBody;
use Guzzle\Service\Aws\S3\S3Exception;
use Guzzle\Service\Aws\S3\Model\ListPartsIterator;

/**
 * This operation lists the parts that have been uploaded for a specific
 * multipart upload.
 *
 * @guzzle bucket doc="Bucket where the object is stored" required="true"
 * @guzzle key doc="Object key" required="true"
 * @guzzle upload_id doc="Upload ID of the upload" required="true"
 * @guzzle max_parts doc="Maximum number of parts to retrieve"
 * @guzzle part_number_marker doc="Specifies the part after which listing should begin. Only parts with higher part numbers will be listed."
 * @guzzle limit doc="The maximum number of parts to retrieve over all iteration"
 * @guzzle xml_only doc="Set to TRUE to return XML data rather than a ListPartsIterator"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ListParts extends AbstractRequestObject
{
    const MAX_PER_REQUEST = 1000;

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'), $this->get('key'));
        
        $this->request->getQuery()->set('uploadId', $this->get('upload_id'));

        if ($this->get('part_number_marker')) {
            $this->request->getQuery()->set('part-number-marker', $this->get('part_number_marker'));
        }
        
        if ($this->get('max_parts')) {
            $this->request->getQuery()->set('max-parts', $this->get('max_parts'));
        }

        $this->applyDefaults($this->request);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $xml = new \SimpleXMLElement($this->getResponse()->getBody(true));
        if ($this->get('xml_only')) {
             $this->result = $xml;
        } else {
            $this->result = ListPartsIterator::factory($this->client, $xml, $this->get('limit', -1));
        }
    }

    /**
     * Returns a ListPartsIterator object
     *
     * @return ListPartsIterator
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Set the max number of parts to retrieve per request
     *
     * @param int $maxParts Maximum number of parts to retrieve per request
     *
     * @return ListParts
     */
    public function setMaxParts($maxParts)
    {
        return $this->set('max_parts', (int)$maxParts);
    }

    /**
     * Set the part after which listing should begin. Only parts with higher
     * part numbers will be listed.
     *
     * @param int $marker Next part marker
     *
     * @return ListParts
     */
    public function setPartNumberMarker($marker)
    {
        return $this->set('part_number_marker', $marker);
    }

    /**
     * Set the upload ID
     *
     * @param string $uploadId Upload ID
     *
     * @return ListParts
     */
    public function setUploadId($uploadId)
    {
        return $this->set('upload_id', $uploadId);
    }

    /**
     * Set to TRUE to format the response only as XML rather than create a new
     * ListPartsIterator
     *
     * @param bool $xmlResponseOnly
     *
     * @return ListParts
     */
    public function setXmlResponseOnly($xmlResponseOnly)
    {
        return $this->set('xml_only', $xmlResponseOnly);
    }

    /**
     * Set the maximum number of parts to retrieve when iterating over results.
     *
     * @param integer $limit Maximum numbuer of parts to retrieve with the iterator
     *
     * @return ListParts
     */
    public function setLimit($limit)
    {
        $this->set('limit', max(0, $limit));
        if ($limit < self::MAX_PER_REQUEST) {
            $this->setMaxParts($limit);
        }

        return $this;
    }
}