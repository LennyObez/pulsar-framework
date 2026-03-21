<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Fluent builder for defining scheduled jobs with readable frequency methods.
 *
 * Usage:
 *   $builder = ScheduleBuilder::job('cleanup', fn($ctx) => ...)
 *       ->dailyAt('03:00')
 *       ->withoutOverlapping()
 *       ->appendOutputTo('/var/log/cleanup.log')
 *       ->evenInMaintenanceMode();
 *
 *   $registry->register($builder->build());
 * @api
 */
#[Api(since: '1.0.0')]
final class ScheduleBuilder
{
    private string $expression = '* * * * *';
    private string $timezone = 'UTC';
    private string $description = '';
    private bool $preventOverlap = false;
    private int $overlapExpiresAfter = 1440;
    private bool $runInMaintenanceMode = false;
    private ?string $outputPath = null;
    private bool $appendOutput = false;
    private ?string $emailOutputTo = null;

    /**
     * @param Closure(JobContext): ?string $callback
     */
    private function __construct(
        private readonly string $name,
        private readonly Closure $callback,
    ) {}

    /**
     * Start building a scheduled job.
     *
     * @param Closure(JobContext): ?string $callback
     */
    #[NoDiscard]
    public static function job(string $name, Closure $callback): self
    {
        return new self($name, $callback);
    }

    /**
     * Run every minute.
     */
    public function everyMinute(): self
    {
        $this->expression = '* * * * *';
        return $this;
    }

    /**
     * Run every five minutes.
     */
    public function everyFiveMinutes(): self
    {
        $this->expression = '*/5 * * * *';
        return $this;
    }

    /**
     * Run every ten minutes.
     */
    public function everyTenMinutes(): self
    {
        $this->expression = '*/10 * * * *';
        return $this;
    }

    /**
     * Run every fifteen minutes.
     */
    public function everyFifteenMinutes(): self
    {
        $this->expression = '*/15 * * * *';
        return $this;
    }

    /**
     * Run every thirty minutes.
     */
    public function everyThirtyMinutes(): self
    {
        $this->expression = '*/30 * * * *';
        return $this;
    }

    /**
     * Run hourly at minute 0.
     */
    public function hourly(): self
    {
        $this->expression = '0 * * * *';
        return $this;
    }

    /**
     * Run hourly at a specific minute.
     */
    public function hourlyAt(int $minute): self
    {
        $this->expression = sprintf('%d * * * *', $minute);
        return $this;
    }

    /**
     * Run daily at midnight.
     */
    public function daily(): self
    {
        $this->expression = '0 0 * * *';
        return $this;
    }

    /**
     * Run daily at a specific time (HH:MM format).
     */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time);
        $this->expression = sprintf('%d %d * * *', (int) $minute, (int) $hour);
        return $this;
    }

    /**
     * Run twice daily at the given hours.
     */
    public function twiceDaily(int $firstHour = 1, int $secondHour = 13): self
    {
        $this->expression = sprintf('0 %d,%d * * *', $firstHour, $secondHour);
        return $this;
    }

    /**
     * Run weekly on a specific day at a specific time.
     *
     * @param int $dayOfWeek 0 = Sunday, 1 = Monday, ..., 6 = Saturday
     * @param string $time HH:MM format
     */
    public function weeklyOn(int $dayOfWeek, string $time = '00:00'): self
    {
        [$hour, $minute] = explode(':', $time);
        $this->expression = sprintf('%d %d * * %d', (int) $minute, (int) $hour, $dayOfWeek);
        return $this;
    }

    /**
     * Run monthly on a specific day at midnight.
     */
    public function monthlyOn(int $dayOfMonth, string $time = '00:00'): self
    {
        [$hour, $minute] = explode(':', $time);
        $this->expression = sprintf('%d %d %d * *', (int) $minute, (int) $hour, $dayOfMonth);
        return $this;
    }

    /**
     * Run on a custom cron expression.
     */
    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    /**
     * Set the timezone for schedule evaluation.
     */
    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    /**
     * Set a description for the job.
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Prevent overlapping executions.
     *
     * @param int $expiresAfterMinutes Lock expiry in minutes (safety valve)
     */
    public function withoutOverlapping(int $expiresAfterMinutes = 1440): self
    {
        $this->preventOverlap = true;
        $this->overlapExpiresAfter = $expiresAfterMinutes;
        return $this;
    }

    /**
     * Allow the job to run even when the application is in maintenance mode.
     */
    public function evenInMaintenanceMode(): self
    {
        $this->runInMaintenanceMode = true;
        return $this;
    }

    /**
     * Write output to a file.
     */
    public function sendOutputTo(string $path): self
    {
        $this->outputPath = $path;
        $this->appendOutput = false;
        return $this;
    }

    /**
     * Append output to a file.
     */
    public function appendOutputTo(string $path): self
    {
        $this->outputPath = $path;
        $this->appendOutput = true;
        return $this;
    }

    /**
     * Email the output to an address after execution.
     */
    public function emailOutputTo(string $email): self
    {
        $this->emailOutputTo = $email;
        return $this;
    }

    /**
     * Build the configured ScheduledJob instance.
     */
    #[NoDiscard]
    public function build(): ScheduledJob
    {
        return new ScheduledJob(
            name: $this->name,
            schedule: new Schedule($this->expression, $this->timezone),
            callback: $this->callback,
            description: $this->description,
            preventOverlap: $this->preventOverlap,
            overlapExpiresAfter: $this->overlapExpiresAfter,
            runInMaintenanceMode: $this->runInMaintenanceMode,
            outputPath: $this->outputPath,
            appendOutput: $this->appendOutput,
            emailOutputTo: $this->emailOutputTo,
        );
    }
}
