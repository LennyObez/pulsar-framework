<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Scheduler\Exception\SchedulerException;

use function array_map;
use function count;
use function explode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Parsed cron expression fields.
 *
 * Standard five-field cron: minute hour dayOfMonth month dayOfWeek
 */
#[Api(since: '1.0.0')]
final readonly class CronFields
{
    public function __construct(
        public string $minute,
        public string $hour,
        public string $dayOfMonth,
        public string $month,
        public string $dayOfWeek,
    ) {}

    /**
     * Parse a cron expression string into fields.
     *
     * @throws SchedulerException If the expression is invalid.
     */
    #[NoDiscard]
    public static function parse(string $expression): self
    {
        $parts = explode(' ', trim($expression));

        if (count($parts) !== 5) {
            throw SchedulerException::invalidCronExpression(
                $expression,
                sprintf('expected 5 fields, got %d', count($parts)),
            );
        }

        foreach ($parts as $i => $part) {
            if (!self::isValidField($part)) {
                throw SchedulerException::invalidCronExpression(
                    $expression,
                    sprintf('invalid field at position %d: "%s"', $i, $part),
                );
            }
        }

        return new self(
            minute: $parts[0],
            hour: $parts[1],
            dayOfMonth: $parts[2],
            month: $parts[3],
            dayOfWeek: $parts[4],
        );
    }

    /**
     * Check if the given time matches this cron expression.
     */
    public function matches(DateTimeImmutable $time): bool
    {
        return self::fieldMatches($this->minute, (int) $time->format('i'))
            && self::fieldMatches($this->hour, (int) $time->format('G'))
            && self::fieldMatches($this->dayOfMonth, (int) $time->format('j'))
            && self::fieldMatches($this->month, (int) $time->format('n'))
            && self::fieldMatches($this->dayOfWeek, (int) $time->format('w'));
    }

    /**
     * Check if a single cron field matches a value.
     */
    private static function fieldMatches(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }

        // Handle comma-separated values: "1,15,30"
        if (str_contains($field, ',')) {
            $values = array_map('intval', explode(',', $field));

            return in_array($value, $values, true);
        }

        // Handle ranges: "1-5"
        if (str_contains($field, '-') && !str_contains($field, '/')) {
            [$min, $max] = array_map('intval', explode('-', $field));

            return $value >= $min && $value <= $max;
        }

        // Handle step values: "*/5" or "1-30/5"
        if (str_contains($field, '/')) {
            [$range, $step] = explode('/', $field);
            $step = (int) $step;

            if ($step <= 0) {
                return false;
            }

            if ($range === '*') {
                return $value % $step === 0;
            }

            if (str_contains($range, '-')) {
                [$min, $max] = array_map('intval', explode('-', $range));

                return $value >= $min && $value <= $max && ($value - $min) % $step === 0;
            }

            $start = (int) $range;

            return $value >= $start && ($value - $start) % $step === 0;
        }

        // Plain number
        return $value === (int) $field;
    }

    /**
     * Validate a single cron field.
     */
    private static function isValidField(string $field): bool
    {
        return (bool) preg_match('/^(\*|[0-9]+(-[0-9]+)?(,[0-9]+(-[0-9]+)?)*)(\/[0-9]+)?$/', $field);
    }
}
