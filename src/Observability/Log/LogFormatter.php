<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use Throwable;

use function is_int;
use function is_object;
use function is_string;

/**
 * Formats LogEntry instances as JSON lines.
 *
 * Handles PSR-3 `{placeholder}` interpolation and serializes Throwable
 * instances in context to structured arrays.
 */
final class LogFormatter
{
    /**
     * Format a log entry as a JSON line.
     */
    public function format(LogEntry $entry): string
    {
        $context = $this->normalizeContext($entry->context);
        $message = $this->interpolate($entry->message, $entry->context);

        $data = [
            'timestamp' => $entry->timestamp->format('Y-m-d\TH:i:s.uP'),
            'level' => $entry->level->value,
            'channel' => $entry->channel,
            'message' => $message,
        ];

        if ($context !== []) {
            $data['context'] = $context;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ($json !== false ? $json : '{}') . "\n";
    }

    /**
     * Interpolate PSR-3 `{placeholder}` tokens in the message.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_string($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Normalize context values for JSON serialization.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $normalized[$key] = $this->serializeThrowable($value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Serialize a Throwable to a structured array.
     *
     * Uses getTrace() and strips function arguments from each frame to prevent
     * PII leaks (passwords, tokens, secrets) that getTraceAsString() would include.
     *
     * @return array<string, mixed>
     */
    private function serializeThrowable(Throwable $throwable): array
    {
        return [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $this->sanitizeTrace($throwable->getTrace()),
        ];
    }

    /**
     * Strip function arguments from each trace frame to prevent PII leaks.
     *
     * @param list<array<string, mixed>> $frames
     * @return list<array{file?: string, line?: int, class?: string, function?: string, type?: string}>
     */
    private function sanitizeTrace(array $frames): array
    {
        $sanitized = [];

        foreach ($frames as $frame) {
            $clean = [];

            if (isset($frame['file']) && is_string($frame['file'])) {
                $clean['file'] = $frame['file'];
            }

            if (isset($frame['line']) && is_int($frame['line'])) {
                $clean['line'] = $frame['line'];
            }

            if (isset($frame['class']) && is_string($frame['class'])) {
                $clean['class'] = $frame['class'];
            }

            if (isset($frame['function']) && is_string($frame['function'])) {
                $clean['function'] = $frame['function'];
            }

            if (isset($frame['type']) && is_string($frame['type'])) {
                $clean['type'] = $frame['type'];
            }

            $sanitized[] = $clean;
        }

        return $sanitized;
    }
}
