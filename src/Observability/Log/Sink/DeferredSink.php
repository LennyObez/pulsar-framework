<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogSinkInterface;
use Throwable;

use function error_log;
use function get_class;
use function sprintf;

/**
 * Deferred log sink that buffers sinks for late-binding.
 *
 * Created early in the Kernel boot pipeline (before Studio config is loaded).
 * Studio's LogCollector is added via addSink() during Studio::attach().
 * When Studio is disabled, this remains a no-op with an empty sinks array.
 *
 * Sink failures are isolated: if one sink throws (e.g. Studio's
 * collector loses its SQLite connection), the others must still run.
 * The exception is reported via PHP's error_log() so the failure
 * surfaces in the operator's standard log channel without bringing
 * down the request — silent swallowing was the previous (C-4) anti-
 * pattern that masked real production incidents.
 */
#[Internal]
final class DeferredSink implements DeferredSinkInterface
{
    /** @var list<LogSinkInterface> */
    private array $sinks = [];

    public function addSink(LogSinkInterface $sink): void
    {
        $this->sinks[] = $sink;
    }

    #[Override]
    public function write(LogEntry $entry): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->write($entry);
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[pulsar.DeferredSink] sink %s failed to write log entry: %s',
                    get_class($sink),
                    $e->getMessage(),
                ));
            }
        }
    }
}
