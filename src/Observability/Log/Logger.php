<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\LoggingChannelConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Observability\Log\Sink\FileSink;
use Pulsar\Observability\Log\Sink\StreamSink;
use Stringable;
use Throwable;

use function fwrite;
use function is_string;
use function sprintf;

/**
 * PSR-3 compliant logger.
 *
 * Dispatches log entries to one or more sinks. Level filtering is applied
 * via the configured threshold. Sink failures are silently swallowed —
 * logging must never crash a request.
 */
#[Api(since: '1.0.0')]
final readonly class Logger implements LoggerInterface
{
    /**
     * @param list<LogSinkInterface> $sinks
     * @param resource|null $stderr Stream to write sink failure notices to (when debug is true)
     */
    public function __construct(
        private array $sinks,
        private LogLevel $threshold,
        private string $channel = 'app',
        private bool $debug = false,
        private mixed $stderr = null,
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
            // Entry creation failure is silently swallowed — logging never crashes a request
            return;
        }

        foreach ($this->sinks as $sink) {
            try {
                $sink->write($entry);
            } catch (Throwable $e) {
                if ($this->debug && $this->stderr !== null) {
                    @fwrite($this->stderr, sprintf("[Pulsar Logger] Sink failure: %s\n", $e->getMessage()));
                }
            }
        }
    }

    private static function createSink(LoggingChannelConfig $config): ?LogSinkInterface
    {
        try {
            return match ($config->driver) {
                'file' => new FileSink($config->path ?? 'var/logs/pulsar.log'),
                'stream' => new StreamSink($config->stream ?? 'php://stderr'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
