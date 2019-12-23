<?php
namespace GuzzleHttp\Cookie;

use GuzzleHttp\Encoder\JsonEncoder;
use GuzzleHttp\Encoder\JsonEncoderInterface;

/**
 * Persists non-session cookies using a JSON formatted file
 */
class FileCookieJar extends CookieJar
{
    /** @var string filename */
    private $filename;

    /** @var bool Control whether to persist session cookies or not. */
    private $storeSessionCookies;

    /** @var JsonEncoderInterface */
    private $jsonEncoder;

    /**
     * Create a new FileCookieJar object
     *
     * @param string $cookieFile File to store the cookie data
     * @param bool $storeSessionCookies Set to true to store session cookies in the cookie jar.
     * @param JsonEncoderInterface|null $jsonEncoder
     */
    public function __construct(
        string $cookieFile,
        bool $storeSessionCookies = false,
        JsonEncoderInterface $jsonEncoder = null
    ) {
        parent::__construct();
        $this->filename = $cookieFile;
        $this->storeSessionCookies = $storeSessionCookies;

        if ($jsonEncoder === null) {
            $this->jsonEncoder = new JsonEncoder();
        } else {
            $this->jsonEncoder = $jsonEncoder;
        }

        if (\file_exists($cookieFile)) {
            $this->load($cookieFile);
        }
    }

    /**
     * Saves the file when shutting down
     */
    public function __destruct()
    {
        $this->save($this->filename);
    }

    /**
     * Saves the cookies to a file.
     *
     * @param string $filename File to save
     *
     * @throws \RuntimeException if the file cannot be found or created
     */
    public function save(string $filename): void
    {
        $json = [];
        /** @var SetCookie $cookie */
        foreach ($this as $cookie) {
            if (CookieJar::shouldPersist($cookie, $this->storeSessionCookies)) {
                $json[] = $cookie->toArray();
            }
        }

        $jsonStr = $this->jsonEncoder->encode($json);
        if (false === \file_put_contents($filename, $jsonStr, LOCK_EX)) {
            throw new \RuntimeException("Unable to save file {$filename}");
        }
    }

    /**
     * Load cookies from a JSON formatted file.
     *
     * Old cookies are kept unless overwritten by newly loaded ones.
     *
     * @param string $filename Cookie file to load.
     *
     * @throws \RuntimeException if the file cannot be loaded.
     */
    public function load(string $filename): void
    {
        $json = \file_get_contents($filename);
        if (false === $json) {
            throw new \RuntimeException("Unable to load file {$filename}");
        } elseif ($json === '') {
            return;
        }

        $data = $this->jsonEncoder->decode($json, true);
        if (\is_array($data)) {
            foreach ($this->jsonEncoder->decode($json, true) as $cookie) {
                $this->setCookie(new SetCookie($cookie));
            }
        } elseif (\strlen($data)) {
            throw new \RuntimeException("Invalid cookie file: {$filename}");
        }
    }
}
