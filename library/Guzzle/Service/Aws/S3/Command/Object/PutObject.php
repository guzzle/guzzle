<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;

/**
 * PUT an object to Amazon S3
 *
 * @guzzle key doc="Object key" required="true"
 * @guzzle bucket doc="Bucket that contains the object" required="true"
 * @guzzle body doc="Body to send to S3" required="true"
 * @guzzle headers doc="Headers to set on the request" type="class:Guzzle\Common\Collection"
 * @guzzle acl doc="Canned ACL to set on the object"
 * @guzzle storage_class doc="Use STANDARD or REDUCED_REDUNDANCY storage"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutObject extends AbstractRequestObjectPut
{
    /**
     * @var bool Whether or not to send a checksum with the PUT
     */
    protected $validateChecksum = true;

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'), $this->get('key'));
        $this->applyDefaults($this->request);
        
        $this->request->setBody($this->get('body'));

        // Add the checksum to the PUT
        if ($this->validateChecksum) {
            $this->request->setHeader('Content-MD5', $this->get('body')->getContentMd5());
        }
    }

    /**
     * Disable checksum validation when sending the object.
     *
     * Calling this method will prevent a Content-MD5 header from being sent in
     * the request.
     *
     * @return PutObject
     */
    public function disableChecksumValidation()
    {
        $this->validateChecksum = false;

        return $this;
    }

    /**
     * Set the body of the object
     *
     * @param string|EntityBody $body Body of the object to set
     *
     * @return PutObject
     */
    public function setBody($body)
    {
        return $this->set('body', EntityBody::factory($body));
    }
}