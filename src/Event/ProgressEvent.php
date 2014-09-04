<?php
namespace GuzzleHttp\Event;

use GuzzleHttp\Transaction;

/**
 * Event object emitted when upload or download progress is made.
 *
 * You can access the progress values using their corresponding public
 * properties:
 *
 * - $downloadSize: The number of bytes that will be downloaded (if known)
 * - $downloaded: The number of bytes that have been downloaded
 * - $uploadSize: The number of bytes that will be uploaded (if known)
 * - $uploaded: The number of bytes that have been uploaded
 */
class ProgressEvent extends AbstractRequestEvent
{
    /** @var int Amount of data to be downloaded */
    public $downloadSize;

    /** @var int Amount of data that has been downloaded */
    public $downloaded;

    /** @var int Amount of data to upload */
    public $uploadSize;

    /** @var int Amount of data that has been uploaded */
    public $uploaded;

    /**
     * @param Transaction $transaction  Transaction being sent.
     * @param int         $downloadSize Amount of data to download (if known)
     * @param int         $downloaded   Amount of data that has been downloaded
     * @param int         $uploadSize   Amount of data to upload (if known)
     * @param int         $uploaded     Amount of data that had been uploaded
     */
    public function __construct(
        Transaction $transaction,
        $downloadSize,
        $downloaded,
        $uploadSize,
        $uploaded
    ) {
        parent::__construct($transaction);
        $this->downloadSize = $downloadSize;
        $this->downloaded = $downloaded;
        $this->uploadSize = $uploadSize;
        $this->uploaded = $uploaded;
    }
}
