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

/**
 * Get an object from a bucket
 *
 * @guzzle bucket doc="Bucket where the object is stored" required="true"
 * @guzzle key doc="Object key" required="true"
 * @guzzle headers doc="Headers to set on the request" type="class:Guzzle\Common\Collection"
 * @guzzle body doc="Entity body to store the response body" type="class:Guzzle\Http\EntityBody"
 * @guzzle range doc="Downloads the specified range of an object"
 * @guzzle if_modified_since" doc="Return the object only if it has been modified since the specified time, otherwise return a 304 (not modified)"
 * @guzzle if_unmodified_since doc="Return the object only if it has not been modified since the specified time, otherwise return a 412 (precondition failed)"
 * @guzzle if_match doc="Return the object only if its entity tag (ETag) is the same as the one specified, otherwise return a 412 (precondition failed)"
 * @guzzle if_none_match doc="Return the object only if its entity tag (ETag) is different from the one specified, otherwise return a 304 (not modified)."
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetObject extends AbstractRequestObject
{
    /**
     * @var bool Whether or not to send a checksum with the GET
     */
    protected $validateChecksum = true;

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'), $this->get('key'));
        $this->applyDefaults($this->request);

        if ($this->get('torrent')) {
            $this->request->getQuery()->add('torrent', null);
        }

        if ($this->hasKey('body')) {
            $this->request->setResponseBody($this->get('body'));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = $this->getResponse();

        // Validate the checksum
        if ($this->validateChecksum && $this->request->isResponseBodyRepeatable()) {
            $expected = trim(str_replace('"', '', $this->getResponse()->getEtag()));
            $received = $this->getResponse()->getBody()->getContentMd5();
            if (strcmp($expected, $received)) {
                throw new S3Exception('Checksum mismatch when downloading object: expected ' . $expected . ', recieved ' . $received);
            }
        }
    }

    /**
     * Disable checksum validation when the object has been retrieved
     *
     * @return PutObject
     */
    public function disableChecksumValidation()
    {
        $this->validateChecksum = false;
        
        return $this;
    }

    /**
     * Set the EntityBody that will hold the response body.  This is useful for
     * downloading to custom destinations other than the default temp stream.
     * This can be used for downloading a file directly to a locally stored file.
     *
     * @param EntityBody $body The body object to download the object body
     *
     * @return GetObject
     */
    public function setResponseBody(EntityBody $body)
    {
        return $this->set('body', $body);
    }

    /**
     * Get the object as a torrent file
     *
     * @param bool $getAsTorrent Set to TRUE to GET the object as a torrent file
     *
     * @return GetObject
     */
    public function setTorrent($getAsTorrent)
    {
        return $this->set('torrent', $getAsTorrent);
    }
}