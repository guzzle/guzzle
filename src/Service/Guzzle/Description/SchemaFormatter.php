<?php

namespace GuzzleHttp\Service\Guzzle\Description;

/**
 * JSON Schema formatter class
 */
class SchemaFormatter
{
    /**
     * Format a value by a registered format name
     *
     * @param string $format Registered format used to format the value
     * @param mixed  $value  Value being formatted
     *
     * @return mixed
     */
    public function format($format, $value)
    {
        switch ($format) {
            case 'date-time':
                return $this->formatDateTime($value);
            case 'date-time-http':
                return $this->formatDateTimeHttp($value);
            case 'date':
                return $this->formatDate($value);
            case 'time':
                return $this->formatTime($value);
            case 'timestamp':
                return $this->formatTimestamp($value);
            case 'boolean-string':
                return $this->formatBooleanAsString($value);
            default:
                return $value;
        }
    }

    /**
     * Perform the actual DateTime formatting
     *
     * @param int|string|\DateTime $dateTime Date time value
     * @param string               $format   Format of the result
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function dateFormatter($dateTime, $format)
    {
        if (is_numeric($dateTime)) {
            return gmdate($format, (int) $dateTime);
        }

        if (is_string($dateTime)) {
            $dateTime = new \DateTime($dateTime);
        }

        if ($dateTime instanceof \DateTime) {
            static $utc;
            if (!$utc) {
                $utc = new \DateTimeZone('UTC');
            }
            return $dateTime->setTimezone($utc)->format($format);
        }

        throw new \InvalidArgumentException('Date/Time values must be either '
            . 'be a string, integer, or DateTime object');
    }

    /**
     * Create a ISO 8601 (YYYY-MM-DDThh:mm:ssZ) formatted date time value in
     * UTC time.
     *
     * @param string|integer|\DateTime $value Date time value
     *
     * @return string
     */
    private function formatDateTime($value)
    {
        return $this->dateFormatter($value, 'Y-m-d\TH:i:s\Z');
    }

    /**
     * Create an HTTP date (RFC 1123 / RFC 822) formatted UTC date-time string
     *
     * @param string|integer|\DateTime $value Date time value
     *
     * @return string
     */
    private function formatDateTimeHttp($value)
    {
        return $this->dateFormatter($value, 'D, d M Y H:i:s \G\M\T');
    }

    /**
     * Create a YYYY-MM-DD formatted string
     *
     * @param string|integer|\DateTime $value Date time value
     *
     * @return string
     */
    private function formatDate($value)
    {
        return $this->dateFormatter($value, 'Y-m-d');
    }

    /**
     * Create a hh:mm:ss formatted string
     *
     * @param string|integer|\DateTime $value Date time value
     *
     * @return string
     */
    private function formatTime($value)
    {
        return $this->dateFormatter($value, 'H:i:s');
    }

    /**
     * Formats a boolean value as a string
     *
     * @param string|integer|bool $value Value to convert to a boolean 'true' / 'false' value
     *
     * @return string
     */
    private function formatBooleanAsString($value)
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    /**
     * Return a UNIX timestamp in the UTC timezone
     *
     * @param string|integer|\DateTime $value Time value
     *
     * @return int
     */
    private function formatTimestamp($value)
    {
        return (int) $this->dateFormatter($value, 'U');
    }
}
