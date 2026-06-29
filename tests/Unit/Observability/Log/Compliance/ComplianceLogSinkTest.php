<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Compliance;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Compliance\ComplianceLogFormatter;
use Pulsar\Observability\Log\Compliance\ComplianceLogSink;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;

use function ini_get;
use function is_string;

/**
 * In-memory sink for testing.
 */
final class InMemoryLogSink implements LogSinkInterface
{
    /** @var list<LogEntry> */
    public array $entries = [];

    #[Override]
    public function write(LogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

#[CoversClass(ComplianceLogSink::class)]
final class ComplianceLogSinkTest extends TestCase
{
    #[Test]
    public function writesToUnderlyingSinkWithoutFormatters(): void
    {
        $inner = new InMemoryLogSink();
        $sink = new ComplianceLogSink($inner);

        $entry = $this->createEntry(['key' => 'value']);
        $sink->write($entry);

        self::assertCount(1, $inner->entries);
        self::assertSame('value', $inner->entries[0]->context['key']);
    }

    #[Test]
    public function appliesFormatterBeforeWriting(): void
    {
        $inner = new InMemoryLogSink();
        $formatter = new class implements ComplianceLogFormatter {
            #[Override]
            public function format(LogEntry $entry): LogEntry
            {
                $context = $entry->context;
                $context['formatted'] = true;

                return new LogEntry(
                    level: $entry->level,
                    message: $entry->message,
                    context: $context,
                    channel: $entry->channel,
                    timestamp: $entry->timestamp,
                );
            }
        };

        $sink = new ComplianceLogSink($inner, null, $formatter);

        $entry = $this->createEntry([]);
        $sink->write($entry);

        self::assertCount(1, $inner->entries);
        self::assertTrue($inner->entries[0]->context['formatted']);
    }

    #[Test]
    public function appliesMultipleFormattersInOrder(): void
    {
        $inner = new InMemoryLogSink();

        $formatter1 = new class implements ComplianceLogFormatter {
            #[Override]
            public function format(LogEntry $entry): LogEntry
            {
                $context = $entry->context;
                $existing = isset($context['order']) && is_string($context['order']) ? $context['order'] : '';
                $context['order'] = $existing . 'A';

                return new LogEntry(
                    level: $entry->level,
                    message: $entry->message,
                    context: $context,
                    channel: $entry->channel,
                    timestamp: $entry->timestamp,
                );
            }
        };

        $formatter2 = new class implements ComplianceLogFormatter {
            #[Override]
            public function format(LogEntry $entry): LogEntry
            {
                $context = $entry->context;
                $existing = isset($context['order']) && is_string($context['order']) ? $context['order'] : '';
                $context['order'] = $existing . 'B';

                return new LogEntry(
                    level: $entry->level,
                    message: $entry->message,
                    context: $context,
                    channel: $entry->channel,
                    timestamp: $entry->timestamp,
                );
            }
        };

        $sink = new ComplianceLogSink($inner, null, $formatter1, $formatter2);

        $entry = $this->createEntry([]);
        $sink->write($entry);

        self::assertSame('AB', $inner->entries[0]->context['order']);
    }

    #[Test]
    public function encryptsLogEntryWhenEncryptorProvided(): void
    {
        $inner = new InMemoryLogSink();

        $encryptor = new class implements EncryptorInterface {
            public string $lastPlaintext = '';

            #[Override]
            public function encrypt(string $plaintext): string
            {
                $this->lastPlaintext = $plaintext;

                return 'ENCRYPTED:' . base64_encode($plaintext);
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                $decoded = base64_decode(substr($encoded, 10), true);

                return $decoded !== false ? $decoded : '';
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): EncryptorInterface
            {
                return $this;
            }
        };

        $sink = new ComplianceLogSink($inner, $encryptor);

        $entry = $this->createEntry(['secret' => 'data']);
        $sink->write($entry);

        self::assertCount(1, $inner->entries);
        $written = $inner->entries[0];
        self::assertStringStartsWith('ENCRYPTED:', $written->message);
        self::assertTrue($written->context['encrypted']);
    }

    #[Test]
    public function logsWarningWhenContextIsNotJsonEncodable(): void
    {
        $inner = new InMemoryLogSink();

        $encryptor = new class implements EncryptorInterface {
            public string $captured = '';

            #[Override]
            public function encrypt(string $plaintext): string
            {
                $this->captured = $plaintext;

                return 'ENC:' . $plaintext;
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                return '';
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): EncryptorInterface
            {
                return $this;
            }
        };

        $sink = new ComplianceLogSink($inner, $encryptor);

        // A lone continuation byte is invalid UTF-8, so json_encode() returns
        // false. Before the fix the loss was silent; now a diagnostic is
        // emitted via error_log and the record falls back to '{}'.
        $entry = $this->createEntry(['blob' => "\xB1\xC3"]);

        $logFile = sys_get_temp_dir() . '/pulsar_complianceink_warn_' . uniqid('', true) . '.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $sink->write($entry);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $emitted = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        self::assertStringContainsString('ComplianceLogSink', $emitted);
        self::assertStringContainsString('json_encode failed', $emitted);

        // The fallback record ('{}') is still written and encrypted, so logging
        // never crashes the request.
        self::assertCount(1, $inner->entries);
        self::assertSame('ENC:{}', $inner->entries[0]->message);
    }

    #[Test]
    public function formattersApplyBeforeEncryption(): void
    {
        $inner = new InMemoryLogSink();

        $formatter = new class implements ComplianceLogFormatter {
            #[Override]
            public function format(LogEntry $entry): LogEntry
            {
                $context = $entry->context;
                $context['masked'] = '****';

                return new LogEntry(
                    level: $entry->level,
                    message: $entry->message,
                    context: $context,
                    channel: $entry->channel,
                    timestamp: $entry->timestamp,
                );
            }
        };

        $encryptor = new class implements EncryptorInterface {
            public string $captured = '';

            #[Override]
            public function encrypt(string $plaintext): string
            {
                $this->captured = $plaintext;

                return 'ENC:' . base64_encode($plaintext);
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                return '';
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): EncryptorInterface
            {
                return $this;
            }
        };

        $sink = new ComplianceLogSink($inner, $encryptor, $formatter);

        $entry = $this->createEntry(['original' => 'data']);
        $sink->write($entry);

        // The encrypted payload should contain the masked value
        self::assertStringContainsString('****', $encryptor->captured);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createEntry(array $context = []): LogEntry
    {
        return new LogEntry(
            level: LogLevel::Info,
            message: 'test',
            context: $context,
            channel: 'app',
            timestamp: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
    }
}
