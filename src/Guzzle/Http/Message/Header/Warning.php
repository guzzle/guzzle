<?php

namespace Guzzle\Http\Message\Header;

use DateTime;

/**
 * HTTP message warning.
 */
class Warning
{
    /**
     * @var int Code
     */
    protected $code;

    /**
     * @var string Agent
     */
    protected $agent;

    /**
     * @var string|null Text
     */
    protected $text;

    /**
     * @var null|DateTime Date
     */
    protected $date;

    /**
     * @var array Default HTTP warning texts
     */
    private static $codeTexts = array(
        110 => 'Response is stale',
        111 => 'Revalidation failed',
        112 => 'Disconnected operation',
        113 => 'Heuristic expiration',
        214 => 'Transformation applied',
    );

    /**
     * Constructor.
     *
     * @param string        $code  Code
     * @param string        $agent Agent
     * @param null|string   $text  Text, or null to use the default
     * @param null|DateTime $date  Date, or null if not given
     */
    public function __construct($code, $agent, $text = null, DateTime $date = null)
    {
        $this->code = (int) $code;
        $this->agent = $agent;

        if (null === $text && array_key_exists($this->code, self::$codeTexts)) {
            $text = self::$codeTexts[$this->code];
        }
        $this->text = $text;

        $this->date = $date;
    }

    /**
     * Create a warning object from the message header string.
     *
     * @param string $header Message header string
     *
     * @return Warning
     */
    public static function fromHeader($header)
    {
        $parts = str_getcsv($header, ' ');

        $code = (int) $parts[0];
        $agent = $parts[1];
        $text = isset($parts[2]) ? $parts[2] : null;
        $date = isset($parts[3]) ? DateTime::createFromFormat('D, d M Y H:i:s e', $parts[3]) : null;

        // @codeCoverageIgnoreStart
        if (false === $date) {
            $date = null;
        }
        // @codeCoverageIgnoreEnd

        return new self($code, $agent, $text, $date);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->asString();
    }

    /**
     * Render the warning as a message header string.
     *
     * @return string
     */
    public function asString()
    {
        if (null !== $this->getDate()) {
            $date = sprintf(' "%s"', $this->date->format('D, d M Y H:i:s e'));
        } else {
            $date = null;
        }

        return sprintf('%d %s "%s"%s', $this->code, $this->agent, $this->text, $date);
    }

    /**
     * Code code.
     *
     * @return int
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Get agent.
     *
     * @return string
     */
    public function getAgent()
    {
        return $this->agent;
    }

    /**
     * Get text.
     *
     * @return string|null
     */
    public function getText()
    {
        return $this->text;
    }

    /**
     * Get date.
     *
     * @return null|DateTime
     */
    public function getDate()
    {
        return $this->date;
    }
}
