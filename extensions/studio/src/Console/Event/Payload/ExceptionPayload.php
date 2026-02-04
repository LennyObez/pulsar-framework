<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Exception event payload.
 */
#[Internal]
final readonly class ExceptionPayload implements ConsoleEvent
{
    /**
     * @param list<array{file: string, line: int, class: ?string, function: ?string}> $stackTrace
     */
    public function __construct(
        public string $exceptionClass,
        public string $message,
        public string $file,
        public int $line,
        public string $fingerprint,
        public array $stackTrace,
        public ?string $previousClass = null,
        public ?string $previousMessage = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::Exception;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'fingerprint' => $this->fingerprint,
            'stack_trace' => $this->stackTrace,
            'previous_class' => $this->previousClass,
            'previous_message' => $this->previousMessage,
        ];
    }
}
