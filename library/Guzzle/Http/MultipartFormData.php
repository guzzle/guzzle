<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http;

use Guzzle\Http\Message\EntityEnclosingRequestInterface;

/**
 * Class for building multipart/form-data entity bodies
 *
 * @link http://www.ietf.org/rfc/rfc1867.txt
 * @link http://www.ietf.org/rfc/rfc2388.txt
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MultipartFormData
{
    /**
     * @var EntityBody
     */
    protected $entityBody;

    /**
     * Factory to build anew MultipartFormData object using the post fields of
     * set on a request object
     *
     * @param EntityEnclosingRequestInterface $request Request
     *
     * @return MultipartFormData
     */
    public static function fromRequestfactory(EntityEnclosingRequestInterface $request)
    {
        return new self($request->getPostFields()->urlEncode(), $request->getPostFiles(), true);
    }

    /**
     * Constructor to set the POST data
     *
     * @param array $fields (optional) Associative array of form fields
     * @param array $files (optional) Associative Array of filenames to use in
     *      the upload, the key of the array is the field name of the file
     */
    public function __construct(array $fields = array(), array $files = array())
    {
        $this->setPostData($fields, $files);
    }

    /**
     * Get the POST body as a string
     *
     * @return string
     */
    public function __toString()
    {
        return (string)$this->entityBody;
    }

    /**
     * Get the POST multipart/form-data entity body as an EntityBody for use
     * with an HTTP POST request
     */
    public function getEntityBody()
    {
        $this->entityBody->seek(0);

        return $this->entityBody;
    }

    /**
     * Rebuild the entity body to send in a POST multipart/form-data request
     *
     * @param array $fields Associative array of form fields
     * @param array $files Associative Array of filenames to use in the upload,
     *      the key of the array is the field name to send in the POST data
     *
     * @return MultipartFormData
     */
    public function setPostData(array $fields, array $files = array())
    {
        $this->entityBody = EntityBody::factory('');

        // Try to create a boundary that is not part of the fields or files
        $boundary = uniqid(md5(microtime()));
        $written = 0;

        // Add the form fields to the POST entity body
        foreach ($fields as $fieldName => $value) {

            if ($written) {
                $this->entityBody->write("\r\n");
            }

            $this->entityBody->write(sprintf(
                "--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s",
                $boundary, $fieldName, $value
            ));
            $written++;
        }

        // Add the files to the POST entity body
        foreach ($files as $fieldName => $file) {

            if (!is_readable($file)) {
                throw new HttpException('Unable to open file ' . $file);
            }

            // Get the mime type of the file
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file);
            finfo_close($finfo);

            if ($written) {
                $this->entityBody->write("\r\n");
            }

            $this->entityBody->write(sprintf(
                "--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n",
                $boundary, $fieldName, basename($file), $mimeType
            ));

            // Add the contents of the file to the entity body
            $fp = fopen($file, 'r');
            while ($data = fread($fp, 8192)) {
                $this->entityBody->write($data);
            }

            $written++;
        }

        return $this;
    }
}