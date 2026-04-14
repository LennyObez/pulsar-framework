<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogFormatter;
use Pulsar\Observability\Log\LogLevel;
use RuntimeException;
use Stringable;

#[CoversClass(LogFormatter::class)]
final class LogFormatterTest extends TestCase
{
    private LogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new LogFormatter();
    }

    #[Test]
    public function formatReturnsJsonLineWithNewline(): void
    {
        $entry = $this->createEntry(LogLevel::Info, 'hello world');

        $result = $this->formatter->format($entry);

        self::assertStringEndsWith("\n", $result);
    }

    #[Test]
    public function formatOutputIsValidJson(): void
    {
        $entry = $this->createEntry(LogLevel::Error, 'an error occurred');

        $result = $this->formatter->format($entry);

        $decoded = json_decode(trim($result), true);
        self::assertIsArray($decoded);
    }

    #[Test]
    public function formatIncludesTimestampWithMicroseconds(): void
    {
        $timestamp = new DateTimeImmutable('2025-03-15T10:30:45.123456+00:00');
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: [],
            channel: 'app',
            timestamp: $timestamp,
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('2025-03-15T10:30:45.123456+00:00', $data['timestamp']);
    }

    #[Test]
    public function formatIncludesLevelValue(): void
    {
        $entry = $this->createEntry(LogLevel::Warning, 'watch out');

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('warning', $data['level']);
    }

    #[Test]
    public function formatIncludesChannel(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Debug,
            message: 'debug info',
            context: [],
            channel: 'security',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('security', $data['channel']);
    }

    #[Test]
    public function formatIncludesInterpolatedMessage(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'User {username} logged in',
            context: ['username' => 'alice'],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('User alice logged in', $data['message']);
    }

    #[Test]
    public function formatInterpolatesStringableObjects(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'Value is {obj}',
            context: ['obj' => $stringable],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('Value is stringable-value', $data['message']);
    }

    #[Test]
    public function formatSkipsNonStringNonStringableForInterpolation(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'Count is {count}',
            context: ['count' => 42],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('Count is {count}', $data['message']);
    }

    #[Test]
    public function formatOmitsContextKeyWhenContextIsEmpty(): void
    {
        $entry = $this->createEntry(LogLevel::Info, 'no context');

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertArrayNotHasKey('context', $data);
    }

    #[Test]
    public function formatIncludesContextWhenNotEmpty(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: ['key' => 'value'],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('context', $data);
        self::assertSame(['key' => 'value'], $data['context']);
    }

    #[Test]
    public function formatSerializesThrowableInContext(): void
    {
        $exception = new RuntimeException('Something went wrong', 42);

        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'failure',
            context: ['exception' => $exception],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('context', $data);
        $context = $data['context'];
        self::assertIsArray($context);
        self::assertArrayHasKey('exception', $context);
        $serialized = $context['exception'];
        self::assertIsArray($serialized);
        self::assertSame(RuntimeException::class, $serialized['class']);
        self::assertSame('Something went wrong', $serialized['message']);
        self::assertSame(42, $serialized['code']);
        self::assertArrayHasKey('file', $serialized);
        self::assertArrayHasKey('line', $serialized);
        self::assertArrayHasKey('trace', $serialized);
        // Trace must be an array of frames, not a raw string
        self::assertIsArray($serialized['trace']);
    }

    #[Test]
    public function formatSanitizesTraceFramesToPreventPiiLeak(): void
    {
        // Create an exception that will have real stack trace frames with arguments
        $exception = new RuntimeException('test');

        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'failure',
            context: ['exception' => $exception],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertIsArray($data['context']);
        $serialized = $data['context']['exception'];
        self::assertIsArray($serialized);
        self::assertIsArray($serialized['trace']);

        // Verify no frame contains 'args' — arguments are stripped to prevent PII leaks
        foreach ($serialized['trace'] as $frame) {
            self::assertIsArray($frame);
            self::assertArrayNotHasKey('args', $frame);
        }
    }

    /**
     * F4.6: trace frame `file` entries must be project-relative —
     * absolute paths (`/var/www/staging/...`, `D:\dev\...`) advertise
     * the deployment topology to log aggregators. Verifies the
     * exception's own `file` and every trace frame's `file` start
     * with one of the project anchors.
     */
    #[Test]
    public function formatRedactsAbsolutePathsInTrace(): void
    {
        $exception = new RuntimeException('test');

        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'failure',
            context: ['exception' => $exception],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertIsArray($data['context']);
        $serialized = $data['context']['exception'];
        self::assertIsArray($serialized);
        self::assertIsString($serialized['file']);

        // Top-level file should not be absolute (must start with one of
        // the project anchors after the leading slash is stripped).
        self::assertMatchesRegularExpression(
            '#^(src|tests|extensions|vendor)/#',
            $serialized['file'],
            'Expected project-relative path, got: ' . $serialized['file'],
        );

        $trace = $serialized['trace'];
        self::assertIsArray($trace);

        foreach ($trace as $frame) {
            self::assertIsArray($frame);
            if (isset($frame['file'])) {
                self::assertIsString($frame['file']);
                self::assertMatchesRegularExpression(
                    '#^(src|tests|extensions|vendor)/#',
                    $frame['file'],
                    'Trace frame leaked absolute path: ' . $frame['file'],
                );
            }
        }
    }

    #[Test]
    public function formatTraceFramesContainOnlySafeKeys(): void
    {
        $exception = new RuntimeException('test');

        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'failure',
            context: ['exception' => $exception],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertIsArray($data['context']);
        self::assertIsArray($data['context']['exception']);
        $trace = $data['context']['exception']['trace'];
        self::assertIsArray($trace);

        $allowedKeys = ['file', 'line', 'class', 'function', 'type'];

        foreach ($trace as $frame) {
            self::assertIsArray($frame);
            foreach (array_keys($frame) as $key) {
                self::assertContains($key, $allowedKeys, "Unexpected key '{$key}' in trace frame");
            }
        }
    }

    #[Test]
    public function formatPreservesNonThrowableContextValues(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Debug,
            message: 'mixed context',
            context: ['string' => 'hello', 'number' => 123, 'array' => [1, 2, 3]],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('context', $data);
        $context = $data['context'];
        self::assertIsArray($context);
        self::assertSame('hello', $context['string']);
        self::assertSame(123, $context['number']);
        self::assertSame([1, 2, 3], $context['array']);
    }

    #[Test]
    public function formatUsesUnescapedSlashesAndUnicode(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'path/to/file',
            context: ['unicode' => "\u{00E9}"],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);

        self::assertStringContainsString('path/to/file', $result);
        self::assertStringContainsString("\u{00E9}", $result);
    }

    #[Test]
    public function formatHandlesMultiplePlaceholders(): void
    {
        $entry = new LogEntry(
            level: LogLevel::Info,
            message: '{action} by {user} on {resource}',
            context: ['action' => 'created', 'user' => 'bob', 'resource' => 'document'],
            channel: 'app',
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $result = $this->formatter->format($entry);
        $data = json_decode(trim($result), true);

        self::assertIsArray($data);
        self::assertSame('created by bob on document', $data['message']);
    }

    #[Test]
    public function formatHandlesAllLogLevels(): void
    {
        $levels = LogLevel::cases();

        foreach ($levels as $level) {
            $entry = $this->createEntry($level, 'test');
            $result = $this->formatter->format($entry);
            $data = json_decode(trim($result), true);

            self::assertIsArray($data);
            self::assertSame($level->value, $data['level']);
        }
    }

    private function createEntry(LogLevel $level, string $message): LogEntry
    {
        return new LogEntry(
            level: $level,
            message: $message,
            context: [],
            channel: 'app',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00.000000+00:00'),
        );
    }
}
