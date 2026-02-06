<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Pulsar\Observability\Tracing\TraceId;
use Throwable;

/**
 * Readonly value object representing a single error occurrence.
 */
final readonly class ErrorEvent
{
    /**
     * @param array<string, mixed> $context Scrubbed request/application context
     * @param list<array{file: string, line: int, class: ?string, function: ?string}> $stackTrace
     */
    public function __construct(
        public ErrorFingerprint $fingerprint,
        public string $exceptionClass,
        public string $message,
        public string $file,
        public int $line,
        public array $stackTrace,
        public array $context,
        public DateTimeImmutable $occurredAt,
        public ?TraceId $traceId = null,
    ) {}

    /**
     * Create an ErrorEvent from a throwable.
     *
     * @param array<string, mixed> $context
     *
     * @throws DateMalformedStringException
     */
    #[NoDiscard]
    public static function fromThrowable(
        Throwable $throwable,
        array $context = [],
        ?TraceId $traceId = null,
    ): self {
        $trace = [];

        foreach ($throwable->getTrace() as $frame) {
            $trace[] = [
                'file' => $frame['file'] ?? '<internal>',
                'line' => $frame['line'] ?? 0,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }

        return new self(
            fingerprint: ErrorFingerprint::fromThrowable($throwable),
            exceptionClass: $throwable::class,
            message: $throwable->getMessage(),
            file: $throwable->getFile(),
            line: $throwable->getLine(),
            stackTrace: $trace,
            context: $context,
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            traceId: $traceId,
        );
    }
}
