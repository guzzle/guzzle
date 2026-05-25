<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use Psr\Log\AbstractLogger;

/**
 * Used for testing purposes.
 *
 * It records all records and gives you access to them for verification.
 */
class TestLogger extends AbstractLogger
{
    public array $records = [];
    public array $recordsByLevel = [];

    public function log($level, $message, array $context = []): void
    {
        $record = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $this->recordsByLevel[$record['level']][] = $record;
        $this->records[] = $record;
    }

    public function hasRecords(string $level): bool
    {
        return isset($this->recordsByLevel[$level]);
    }

    /**
     * @param string|array<string, mixed> $record
     */
    public function hasRecord($record, string $level): bool
    {
        if (is_string($record)) {
            $record = ['message' => $record];
        }

        return $this->hasRecordThatPasses(static function (array $rec) use ($record): bool {
            if ($rec['message'] !== $record['message']) {
                return false;
            }
            if (isset($record['context']) && $rec['context'] !== $record['context']) {
                return false;
            }

            return true;
        }, $level);
    }

    public function hasRecordThatContains(string $message, string $level): bool
    {
        return $this->hasRecordThatPasses(static function (array $rec) use ($message): bool {
            return strpos($rec['message'], $message) !== false;
        }, $level);
    }

    public function hasRecordThatMatches(string $regex, string $level): bool
    {
        return $this->hasRecordThatPasses(static function (array $rec) use ($regex): bool {
            return preg_match($regex, $rec['message']) > 0;
        }, $level);
    }

    public function hasRecordThatPasses(callable $predicate, string $level): bool
    {
        if (!isset($this->recordsByLevel[$level])) {
            return false;
        }
        foreach ($this->recordsByLevel[$level] as $i => $rec) {
            if ($predicate($rec, $i)) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->records = [];
        $this->recordsByLevel = [];
    }
}
