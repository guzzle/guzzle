<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\EntityBody;

/**
 * Submit a file for processing
 *
 * Documentation:
 * The SubmitFeed operation uploads a file for processing together with the necessary metadata
 * to process the file.
 * 
 * Amazon MWS limits calls to 1,000 total calls per hour per seller account. For
 * best performance, you should limit your calls to SubmitFeed to no more than
 * three feeds per hour per seller account, although you can successfully call
 * SubmitFeed up to 30 times per hour. Feed size is limited to 2,147,483,647
 * bytes (2^31 -1) per feed. If you have a large amount of data to post,
 * however, we recommend when possible that you submit feeds smaller than this 
 * limit; submit feeds when you have 30,000 records/items or four hours have
 * passed since your last submittal, whichever comes first. This ensures
 * optimal feed processing performance.
 *
 * The client must transmit a User-Agent header line so that we can diagnose
 * problematic HTTP client software. For more information about the User-Agent
 * header line, see the topic, User-Agent Header.
 *
 * The Content-MD5 HTTP header is required when calling SubmitFeed. It must be
 * computed as per section 14.15 of the HTTP/1.1 Specification
 * (http://www.ietf.org/rfc/rfc2616.txt). For more information, see the topic,
 * Using the Content-MD5 Header with SubmitFeed.
 *
 * The actual format of the FeedContent in the HTTP body of the SubmitFeed call
 * varies by marketplace, seller, product category, and by other factors.
 *
 * For additional information, see Related Resources.
 *
 * In North America and Europe, transmit a Content-Type of
 * "text/tab-separated-values; charset=iso-8859-1".
 * In Japan, "text/tab-separated-values; charset=Shift_JIS".
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle feed_content doc="The actual content of the feed" required="true"
 * @guzzle feed_type doc="FeedType value" required="true"
 * @guzzle purge_and_replace doc="Set to tre to enable purge/replace"
 */
class SubmitFeed extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'SubmitFeed';

    protected $requestMethod = RequestInterface::POST;

    protected $body;

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $feedContent = $this->get('feed_content');
        $this->remove('feed_content');
        
        parent::build();

        $this->body = EntityBody::factory($feedContent);
        $this->request->setBody($this->body);

        $this->request->setHeader('Content-Type', $this->body->getContentType());
        $this->request->setHeader('Content-MD5', $this->body->getContentMd5(true, true));
    }

    /**
     * Set feed content
     *
     * @param string $feedContent
     *
     * @return SubmitFeed
     */
    public function setFeedContent($feedContent)
    {
        return $this->set('feed_content', $feedContent);
    }

    /**
     * Set feed type
     *
     * @param string $feedType
     *
     * @return SubmitFeed
     */
    public function setFeedType($feedType)
    {
        return $this->set('feed_type', $feedType);
    }

    /**
     * Set purge and replace
     *
     * @param bool $purgeAndReplace
     *
     * @return SubmitFeed
     */
    public function setPurgeAndReplace($purgeAndReplace)
    {
        return $this->set('purge_and_replace', $purgeAndReplace);
    }

}