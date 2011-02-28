<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Service\Aws\S3\S3Client;

/**
 * The HEAD operation retrieves metadata from an object without returning the
 * object itself. This operation is useful if you're only interested in an
 * object's metadata. To use HEAD, you must have READ access to the object.
 * If READ access is granted to the anonymous user, you can request the
 * object's metadata without an authorization header.
 *
 * A HEAD request has the same options as a GET operation on an object. The
 * response is identical to the GET response, except that there is no response
 * body.
 *
 * @guzzle bucket doc="Bucket where the object is stored" required="true"
 * @guzzle key doc="Object key" required="true"
 * @guzzle headers doc="Headers to set on the request" type="class:Guzzle\Common\Collection"
 * @guzzle range doc="Downloads the specified range of an object"
 * @guzzle if_modified_since" doc="Return the object only if it has been modified since the specified time, otherwise return a 304 (not modified)"
 * @guzzle if_unmodified_since doc="Return the object only if it has not been modified since the specified time, otherwise return a 412 (precondition failed)"
 * @guzzle if_match doc="Return the object only if its entity tag (ETag) is the same as the one specified, otherwise return a 412 (precondition failed)"
 * @guzzle if_none_match doc="Return the object only if its entity tag (ETag) is different from the one specified, otherwise return a 304 (not modified)."
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class HeadObject extends AbstractRequestObject
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::HEAD, $this->get('bucket'), $this->get('key'));
        $this->applyDefaults($this->request);
    }
}