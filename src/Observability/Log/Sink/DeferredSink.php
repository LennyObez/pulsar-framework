<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogSinkInterface;
use Throwable;

/**
 * Deferred log sink that buffers sinks for late-binding.
 *
 * Created early in the Kernel boot pipeline (before Studio config is loaded).
 * Studio's LogCollector is added via addSink() during Studio::attach().
 * When Studio is disabled, this remains a no-op with an empty sinks array.
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
            } catch (Throwable) {
            }
        }
    }
}
