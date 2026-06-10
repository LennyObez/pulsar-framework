<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use Closure;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Stringable;
use Throwable;

use function error_log;
use function fwrite;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * PSR-3 logger with runtime-adjustable minimum level.
 *
 * Designed for persistent workers (RoadRunner, FrankenPHP) where the log
 * verbosity needs to change without restarting the process: for example,
 * switching to debug level during an incident investigation.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final class MutableLogger implements LoggerInterface
{
    private LogLevel $threshold;

    /**
     * @param list<LogSinkInterface> $sinks
     * @param resource|null $stderr Stream to write sink failure notices to (when debug is true)
     * @param Closure(string):void|null $fallbackEmitter Last-resort sink-failure emitter.
     *                                                   Defaults to PHP's `error_log()`. Tests pass an
     *                                                   in-memory buffer; CI / prod leaves it null.
     */
    public function __construct(
        private readonly array $sinks,
        LogLevel $threshold,
        private readonly string $channel = 'app',
        private readonly bool $debug = false,
        private mixed $stderr = null,
        private readonly ?Closure $fallbackEmitter = null,
    ) {
        $this->threshold = $threshold;
    }

    /**
     * Change the minimum log level at runtime.
     *
     * Messages below this level are silently discarded.
     */
    public function setMinLevel(LogLevel $level): void
    {
        $this->threshold = $level;
    }

    /**
     * Get the current minimum log level.
     */
    public function minLevel(): LogLevel
    {
        return $this->threshold;
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Alert, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelParam = $level instanceof LogLevel ? $level : (is_string($level) ? $level : 'debug');
        $logLevel = LogLevel::fromPsrLevel($levelParam);

        if (!$logLevel->meetsThreshold($this->threshold)) {
            return;
        }

        /** @var array<string, mixed> $context */
        try {
            $entry = LogEntry::create(
                level: $logLevel,
                message: (string) $message,
                context: $context,
                channel: $this->channel,
            );
        } catch (Throwable) {
            return;
        }

        $atLeastOneSucceeded = false;

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($entry);
                $atLeastOneSucceeded = true;
            } catch (Throwable $e) {
                if ($this->debug && $this->stderr !== null) {
                    @fwrite($this->stderr, sprintf("[Pulsar Logger] Sink failure: %s\n", $e->getMessage()));
                }
            }
        }

        // Mirror Logger::log() F4.2 durability: when every sink dropped the
        // entry (or none were configured), persist it through the fallback
        // emitter so the audit trail is not lost. MutableLogger is the
        // persistent-worker variant where audit durability matters most, so
        // a complete sink failure must still produce a signal even outside
        // debug mode.
        if (!$atLeastOneSucceeded) {
            $line = new LogFormatter()->format($entry);
            $this->emitFallback('[Pulsar Logger fallback] ' . rtrim($line, "\n"));
        }
    }

    private function emitFallback(string $message): void
    {
        if ($this->fallbackEmitter !== null) {
            ($this->fallbackEmitter)($message);

            return;
        }

        @error_log($message);
    }
}
