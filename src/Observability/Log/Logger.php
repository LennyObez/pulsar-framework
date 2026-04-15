<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use Closure;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\LoggingChannelConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Observability\Log\Sink\FileSink;
use Pulsar\Observability\Log\Sink\StreamSink;
use Stringable;
use Throwable;

use function error_log;
use function fwrite;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * PSR-3 compliant logger.
 *
 * Dispatches log entries to one or more sinks. Level filtering is applied
 * via the configured threshold.
 *
 * F4.2: sink-write failures used to be swallowed silently — a misconfigured
 * file path or unwritable disk simply dropped every entry. For a regulated
 * deployment (PSD2 / GDPR / PCI), silent log loss is itself a compliance
 * incident: the audit trail looks intact when it isn't. The logger now
 * uses `error_log()` (PHP's bottom-of-stack diagnostic channel, configured
 * via `error_log` ini) as a last-resort fallback when no sink can accept
 * the entry, and emits a separate `error_log` line announcing which sink
 * failed so operators see the incident even when the primary log file is
 * unreachable. Logging still never crashes a request.
 *
 * F4.3: sink construction failures are routed through the same channel —
 * `createSink()` no longer returns null when the constructor throws; the
 * failure is announced via `error_log` and the channel is dropped.
 */
#[Api(since: '1.0.0')]
final readonly class Logger implements LoggerInterface
{
    /**
     * @param list<LogSinkInterface> $sinks
     * @param resource|null $stderr Stream to write sink failure notices to (when debug is true)
     * @param Closure(string):void|null $fallbackEmitter Last-resort sink-failure emitter.
     *                                                   Defaults to PHP's `error_log()`. Tests pass an
     *                                                   in-memory buffer; CI / prod leaves it null.
     */
    public function __construct(
        private array $sinks,
        private LogLevel $threshold,
        private string $channel = 'app',
        private bool $debug = false,
        private mixed $stderr = null,
        private ?Closure $fallbackEmitter = null,
    ) {}

    /**
     * Build a Logger from ObservabilityConfig.
     */
    #[NoDiscard]
    public static function fromConfig(ObservabilityConfig $config): self
    {
        return self::fromConfigWithExtraSinks($config, []);
    }

    /**
     * Build a Logger from ObservabilityConfig with additional sinks appended.
     *
     * @param list<LogSinkInterface> $extraSinks
     */
    #[NoDiscard]
    public static function fromConfigWithExtraSinks(ObservabilityConfig $config, array $extraSinks): self
    {
        $threshold = LogLevel::fromPsrLevel($config->loggingLevel);
        $sinks = [];

        foreach ($config->loggingChannels as $channelConfig) {
            $sink = self::createSink($channelConfig);

            if ($sink !== null) {
                $sinks[] = $sink;
            }
        }

        foreach ($extraSinks as $sink) {
            $sinks[] = $sink;
        }

        return new self(
            sinks: $sinks,
            threshold: $threshold,
            channel: $config->defaultLoggingChannel,
        );
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
            // Entry creation failure is silently swallowed; logging never crashes a request
            return;
        }

        $atLeastOneSucceeded = false;

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($entry);
                $atLeastOneSucceeded = true;
            } catch (Throwable $e) {
                $this->reportSinkFailure($sink, $e);
            }
        }

        // F4.2: when every sink dropped the entry (or none were
        // configured), persist it through the fallback emitter so
        // the record is not lost. This is the last-resort durability
        // path — operators should still investigate the sink
        // failures, but a banking-grade audit trail must not vanish.
        if (!$atLeastOneSucceeded) {
            $line = (new LogFormatter())->format($entry);
            $this->emitFallback('[Pulsar Logger fallback] ' . rtrim($line, "\n"));
        }
    }

    private function reportSinkFailure(LogSinkInterface $sink, Throwable $e): void
    {
        if ($this->debug && $this->stderr !== null) {
            @fwrite($this->stderr, sprintf("[Pulsar Logger] Sink failure: %s\n", $e->getMessage()));
        }

        // F4.2: surface the failure on PHP's bottom-of-stack diagnostic
        // channel even outside debug mode. Production operators rely
        // on `error_log` for fatal-tier signals; silent sink failures
        // were previously invisible.
        $this->emitFallback(sprintf('[Pulsar Logger] sink %s failure: %s', $sink::class, $e->getMessage()));
    }

    private function emitFallback(string $message): void
    {
        if ($this->fallbackEmitter !== null) {
            ($this->fallbackEmitter)($message);

            return;
        }

        @error_log($message);
    }

    private static function createSink(LoggingChannelConfig $config): ?LogSinkInterface
    {
        try {
            return match ($config->driver) {
                'file' => new FileSink($config->path ?? 'var/logs/pulsar.log'),
                'stream' => new StreamSink($config->stream ?? 'php://stderr'),
                default => null,
            };
        } catch (Throwable $e) {
            // F4.3: a sink that fails to construct is dropped from
            // the channel list, but operators must know — otherwise
            // a typo in `path` or a missing `stream` resource
            // silently kills the whole channel and the rest of the
            // app keeps logging into the void.
            @error_log(sprintf(
                '[Pulsar Logger] sink driver "%s" failed to construct: %s',
                $config->driver,
                $e->getMessage(),
            ));

            return null;
        }
    }
}
