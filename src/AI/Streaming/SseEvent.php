<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Pulsar\Api\Internal;

/**
 * One `text/event-stream` frame: its `event:` name and its joined `data:` lines.
 */
#[Internal(reason: 'Wire format detail of the SSE providers; consume AiStreamDelta instead')]
final readonly class SseEvent
{
    /**
     * @param string $name The `event:` field, empty when the frame omitted it
     * @param string $data The `data:` fields joined with newlines, per the SSE spec
     */
    public function __construct(
        public string $name,
        public string $data,
    ) {}
}
