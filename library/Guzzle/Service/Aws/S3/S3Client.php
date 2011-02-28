<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3;

use Guzzle\Service\Aws\AbstractClient;
use Guzzle\Http\QueryString;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Request;
use Guzzle\Guzzle;

/**
 * Client for interacting with Amazon S3
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle access_key_id doc="AWS Access Key ID"
 * @guzzle secret_access_key doc="AWS Secret Access Key"
 * @guzzle region required="true" default="s3.amazonaws.com" doc="AWS Region endpoint"
 * @guzzle protocol required="true" default="http" doc="HTTP protocol (http or https)"
 * @guzzle base_url required="true" default="{{ protocol }}://{{ region }}/" doc="Amazon S3 endpoint"
 *
 * @guzzle cache.key_filter static="header=Date, Authorization; query=Timestamp, Signature"
 */
class S3Client extends AbstractClient
{
    const BUCKET_LOCATION_US = 'US';
    const BUCKET_LOCATION_EU = 'EU';
    const BUCKET_LOCATION_US_WEST_1 = 'us-west-1';
    const BUCKET_LOCATION_AP_SOUTHEAST_1 = 'ap-southeast-1';

    const ACL_PRIVATE = 'private';
    const ACL_PUBLIC_READ = 'public-read';
    const ACL_READ_WRITE = 'public-read-write';
    const ACL_AUTH_READ = 'authenticated-read';
    const ACL_OWNER_READ = 'bucket-owner-read';
    const ACL_OWNER_FULL = 'bucket-owner-full-control';

    const PAYER_REQUESTER = 'Requester';
    const PAYER_BUCKET_OWNER = 'BucketOwner';

    const GRANT_TYPE_EMAIL = 'AmazonCustomerByEmail';
    const GRANT_TYPE_USER = 'CanonicalUser';
    const GRANT_TYPE_GROUP = 'Group';

    const GRANT_AUTH = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';
    const GRANT_ALL = 'http://acs.amazonaws.com/groups/global/AllUsers';
    const GRANT_LOG = 'http://acs.amazonaws.com/groups/s3/LogDelivery';

    const GRANT_READ = 'READ';
    const GRANT_WRITE = 'WRITE';
    const GRANT_READ_ACP = 'READ_ACP';
    const GRANT_WRITE_ACP = 'WRITE_ACP';
    const GRANT_FULL_CONTROL = 'FULL_CONTROL';

    /**
     * @var bool Force the client reference buckets using path hosting
     */
    protected $forcePathHosting = false;

    /**
     * Find out if a string is a valid name for an Amazon S3 bucket.
     *
     * @param string $bucket The name of the bucket to check.
     *
     * @return bool TRUE if the bucket name is valid or FALSE if it is invalid.
     */
    public static function isValidBucketName($bucket)
    {
        $bucketLen = strlen($bucket);
        if ($bucketLen < 3
            // 3 < bucket < 63
            || $bucketLen > 63
            // Cannot start or end with a '.'
            || $bucket[0] == '.'
            || $bucket[$bucketLen - 1] == '.'
            // Cannot look like an IP address
            || preg_match('/^\d+\.\d+\.\d+\.\d+$/', $bucket)
            // Cannot include special characters or _
            || !preg_match('/^[a-z0-9]([a-z0-9\\-.]*[a-z0-9])?$/', $bucket)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the client is forcing path hosting buckets
     *
     * @return bool
     */
    public function isPathHostingBuckets()
    {
        return $this->forcePathHosting;
    }

    /**
     * Set whether or not the client is forcing path hosting buckets
     *
     * @param bool $forcePathHosting Set to TRUE to reference buckets using the
     *      path hosting address
     *
     * @return S3Client
     */
    public function setForcePathHostingBuckets($forcePathHostring)
    {
        $this->forcePathHosting = (bool)$forcePathHostring;
        
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Configures a request for use with Amazon S3
     */
    public function getRequest($httpMethod, $headers = null, $body = null)
    {
        $request = parent::getRequest($httpMethod, $headers, $body);
        $request->setHeader('Date', Guzzle::getHttpDate('now'))
                ->setHeader('Host', $request->getHost());

        return $request;
    }

    /**
     * Get an Amazon S3 ready request
     *
     * @param string $method The HTTP method
     * @param string $bucket (optional)
     * @param string $key (optional)
     *
     * @return RequestInterface
     */
    public function getS3Request($method, $bucket = null, $key = null)
    {
        $request = $this->getRequest($method);

        if (!$bucket) {
            return $request;
        }

        $bucket = rawurlencode($bucket);

        if ($this->forcePathHosting) {
            $url = $this->getBaseUrl() . $bucket;
        } else {
            $url = $this->injectConfig('{{ protocol }}://' . $bucket . '.{{ region }}/');
        }

        if ($key) {
            if (strcmp($url[strlen($url) - 1], '/')) {
                $url .= '/';
            }
            $url .= rawurlencode($key);
        }

        $request->setUrl($url);

        return $request;
    }

    /**
     * Get a signed URL that is valid for a specific amount of time	for a virtual
     * hosted bucket.
     *
     * @param string $bucket The bucket of the object.
     * @param string $key The key of the object.
     * @param int $duration The number of seconds the URL is valid.
     * @param bool $cnamed Whether or not the bucket should be referenced by a
     *      CNAMEd URL.
     * @param bool $torrent Set to true to append ?torrent and retrieve the
     *      torrent of the file.
     *
     * @return string Returns a signed URL.
     *
     * @throws LogicException when $torrent and $requesterPays is passed.
     *
     * @link http://docs.amazonwebservices.com/AmazonS3/2006-03-01/index.html?RESTAuthentication.html
     */
    public function getSignedUrl($bucket, $key, $duration, $cnamed = false, $torrent = false, $requesterPays = false)
    {
        if ($torrent && $requesterPays) {
            throw new \InvalidArgumentException('Cannot use ?requesterPays with ?torrent.');
        }

        $expires = time() + (($duration) ? $duration : 60);
        $plugin = $this->getPlugin('Guzzle\\Service\\Aws\\S3\\SignS3RequestPlugin');
        $isSigned = ($plugin != false);
        $xAmzHeaders = $torrentStr = '';
        $url = 'http://' . $bucket . (($cnamed) ? '' : ('.' . $this->config->get('region')));

        if ($key) {
            $url .= '/' . $key;
        }

        $qs = new QueryString();

        if ($isSigned) {
            $qs->add('AWSAccessKeyId', $this->getAccessKeyId())
               ->add('Expires', $expires);
        }

        if ($torrent) {
            $qs->add('torrent', false);
            $torrentStr = '?torrent';
        } else if ($requesterPays) {
            $qs->add('x-amz-request-payer', 'requester');
            $xAmzHeaders .= 'x-amz-request-payer:requester' . "\n";
        }

        if ($isSigned) {
            $strToSign = sprintf("GET\n\n\n{$expires}\n{$xAmzHeaders}/%s/%s{$torrentStr}", QueryString::rawurlencode($bucket, array('/')), QueryString::rawurlencode($key, array('/')));
            $qs->add('Signature', $plugin->getSignature()->signString($strToSign));
        }

        return $url . $qs;
    }
}