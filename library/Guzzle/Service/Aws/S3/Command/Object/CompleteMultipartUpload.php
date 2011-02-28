<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\BadResponseException;

/**
 * This operation completes a multipart upload
 *
 * @guzzle key doc="Object key" required="true"
 * @guzzle bucket doc="Bucket that contains the object" required="true"
 * @guzzle upload_id doc="Upload ID" required="true"
 * @guzzle parts doc="Upload parts" required="true"
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 * @link   http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?mpUploadComplete.html
 */
class CompleteMultipartUpload extends AbstractRequestObject
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::POST, $this->get('bucket'), $this->get('key'));
        $this->request->getQuery()->set('uploadId', $this->get('upload_id'));
        $this->applyDefaults($this->request);

        // Build the multipart upload body
        $body = '<CompleteMultipartUpload>';
        foreach ($this->get('parts') as $part) {

            // Add wrapping quotes to the etag
            if ($part['etag'][0] != '"') {
                $part['etag'] = '"' . $part['etag'];
            }

            if (substr($part['etag'], -1, 1) != '"') {
                $part['etag'] .= '"';
            }

            $body .= '<Part>' .
                '<PartNumber>' . $part['part_number'] . '</PartNumber>' .
                '<ETag>' . $part['etag'] . '</ETag>' .
                '</Part>';
        }
        $body .= '</CompleteMultipartUpload>';
        
        $this->request->setBody(EntityBody::factory($body));
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        // Make sure a special error did not occur
        if (strpos($this->getResponse()->getBody(true), '<Error>')) {
            throw new BadResponseException();
        }

        $this->result = new \SimpleXMLElement($this->getResponse()->getBody(true));
    }

    /**
     * Get the SimpleXMLElement containing information about the completed
     * multipart upload.  Elements of interest are:
     * Location, Bucket, Key, and ETag
     * 
     * @return SimpleXMLElement
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Set the upload ID of the request
     *
     * @param string $uploadId Upload ID
     *
     * @return CompleteMultipartUpload
     */
    public function setUploadId($uploadId)
    {
        return $this->set('upload_id', $uploadId);
    }

    /**
     * Set the upload part data of the request.  The data should be an array
     * containing an associative array of upload part data, each associative
     * array must contain a part_number key and an etag key.
     *
     * @param string $parts Array of part data
     *
     * @return CompleteMultipartUpload
     *
     * <code>
     * $command = new CompleteMultipartUpload();
     *
     * $command->setParts(array(
     *     array(
     *         'part_number' => '1',
     *         'etag' => 'a54357aff0632cce46d942af68356b38'
     *     ),
     *     array(
     *         'part_number' => '2',
     *         'etag' => '0c78aef83f66abc1fa1e8477f296d394'
     *     )
     * ));
     * </code>
     */
    public function setParts(array $parts)
    {
        return $this->set('parts', $parts);
    }
}