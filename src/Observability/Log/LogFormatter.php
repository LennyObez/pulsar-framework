<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log;

use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Throwable;

use function is_int;
use function is_object;
use function is_string;
use function str_replace;
use function strrpos;
use function substr;

/**
 * Formats LogEntry instances as JSON lines.
 *
 * Handles PSR-3 `{placeholder}` interpolation and serializes Throwable
 * instances in context to structured arrays.
 */
final class LogFormatter
{
    private readonly SensitiveDataScrubber $stringScrubber;

    public function __construct(?SensitiveDataScrubber $stringScrubber = null)
    {
        $this->stringScrubber = $stringScrubber ?? new SensitiveDataScrubber();
    }

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

        /** @var mixed $value */
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
        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

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
     * F4.6: file paths from `getFile()` and trace frames are normalised to
     * project-relative form so absolute paths (`/var/www/.../src/Foo.php`,
     * `D:\dev\...`) do not leak deployment topology to log aggregators.
     *
     * @return array<string, mixed>
     */
    private function serializeThrowable(Throwable $throwable): array
    {
        return [
            'class' => $throwable::class,
            // F8.5: Throwable messages frequently embed user-supplied
            // values (record ids, URLs, free text). When that includes
            // a credit-card number, JWT, or long token, the message
            // surfaces the secret to log aggregators. The scrubber's
            // string-pattern pass redacts those before serialisation.
            'message' => $this->stringScrubber->scrubString($throwable->getMessage()),
            'code' => $throwable->getCode(),
            'file' => self::redactPath($throwable->getFile()),
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
                $clean['file'] = self::redactPath($frame['file']);
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

    /**
     * F4.6: collapse an absolute path to project-relative form so trace
     * frames do not advertise the deployment root. Anchors on the
     * standard repository directories. Mirrors the same trick used by
     * ErrorFingerprint::normaliseFile so a fingerprint and its trace
     * frames stay consistent.
     */
    private static function redactPath(string $file): string
    {
        $unixPath = str_replace('\\', '/', $file);

        $anchors = ['/src/', '/tests/', '/extensions/', '/vendor/'];

        foreach ($anchors as $anchor) {
            $position = strrpos($unixPath, $anchor);

            if ($position !== false) {
                return substr($unixPath, $position + 1);
            }
        }

        return $unixPath;
    }
}
