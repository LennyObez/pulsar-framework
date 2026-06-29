<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Compliance;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Compliance\GdprLogFormatter;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(GdprLogFormatter::class)]
final class GdprLogFormatterTest extends TestCase
{
    private const string TEST_HMAC_KEY = 'test-hmac-key-for-gdpr-log-formatter';

    private GdprLogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GdprLogFormatter(self::TEST_HMAC_KEY);
    }

    #[Test]
    public function pseudonymizesUserId(): void
    {
        $entry = $this->createEntry(['user_id' => 'user-42']);

        $result = $this->formatter->format($entry);

        $userId = $result->context['user_id'];
        self::assertIsString($userId);
        self::assertStringStartsWith('pseudonym_', $userId);
        self::assertNotSame('user-42', $userId);
    }

    #[Test]
    public function pseudonymizesEmail(): void
    {
        $entry = $this->createEntry(['email' => 'alice@example.com']);

        $result = $this->formatter->format($entry);

        $email = $result->context['email'];
        self::assertIsString($email);
        self::assertStringStartsWith('pseudonym_', $email);
        self::assertStringNotContainsString('alice', $email);
    }

    #[Test]
    public function pseudonymizesSubjectId(): void
    {
        $entry = $this->createEntry(['subject_id' => 'sub-123']);

        $result = $this->formatter->format($entry);

        $subjectId = $result->context['subject_id'];
        self::assertIsString($subjectId);
        self::assertStringStartsWith('pseudonym_', $subjectId);
    }

    #[Test]
    public function pseudonymizesName(): void
    {
        $entry = $this->createEntry(['name' => 'Alice Smith']);

        $result = $this->formatter->format($entry);

        $name = $result->context['name'];
        self::assertIsString($name);
        self::assertStringStartsWith('pseudonym_', $name);
        self::assertStringNotContainsString('Alice', $name);
    }

    #[Test]
    public function pseudonymizesIpAddress(): void
    {
        $entry = $this->createEntry(['ip_address' => '192.168.1.1']);

        $result = $this->formatter->format($entry);

        $ip = $result->context['ip_address'];
        self::assertIsString($ip);
        self::assertStringStartsWith('pseudonym_', $ip);
        self::assertStringNotContainsString('192.168', $ip);
    }

    #[Test]
    public function consistentPseudonymization(): void
    {
        $entry1 = $this->createEntry(['email' => 'alice@example.com']);
        $entry2 = $this->createEntry(['email' => 'alice@example.com']);

        $result1 = $this->formatter->format($entry1);
        $result2 = $this->formatter->format($entry2);

        self::assertSame($result1->context['email'], $result2->context['email']);
    }

    #[Test]
    public function differentInputsProduceDifferentPseudonyms(): void
    {
        $entry1 = $this->createEntry(['email' => 'alice@example.com']);
        $entry2 = $this->createEntry(['email' => 'bob@example.com']);

        $result1 = $this->formatter->format($entry1);
        $result2 = $this->formatter->format($entry2);

        self::assertNotSame($result1->context['email'], $result2->context['email']);
    }

    #[Test]
    public function preservesNonPersonalFields(): void
    {
        $entry = $this->createEntry([
            'action' => 'login',
            'status' => 'success',
            'duration_ms' => 42,
        ]);

        $result = $this->formatter->format($entry);

        self::assertSame('login', $result->context['action']);
        self::assertSame('success', $result->context['status']);
        self::assertSame(42, $result->context['duration_ms']);
    }

    #[Test]
    public function handlesMultiplePersonalFieldsInSameEntry(): void
    {
        $entry = $this->createEntry([
            'user_id' => 'user-42',
            'email' => 'alice@example.com',
            'name' => 'Alice Smith',
            'action' => 'profile_view',
        ]);

        $result = $this->formatter->format($entry);

        $userId = $result->context['user_id'];
        $email = $result->context['email'];
        $name = $result->context['name'];
        self::assertIsString($userId);
        self::assertIsString($email);
        self::assertIsString($name);
        self::assertStringStartsWith('pseudonym_', $userId);
        self::assertStringStartsWith('pseudonym_', $email);
        self::assertStringStartsWith('pseudonym_', $name);
        self::assertSame('profile_view', $result->context['action']);
    }

    #[Test]
    public function supportsCustomFieldList(): void
    {
        $formatter = new GdprLogFormatter(self::TEST_HMAC_KEY, ['custom_field']);
        $entry = $this->createEntry([
            'custom_field' => 'sensitive-data',
            'user_id' => 'user-42',
        ]);

        $result = $formatter->format($entry);

        $customField = $result->context['custom_field'];
        self::assertIsString($customField);
        self::assertStringStartsWith('pseudonym_', $customField);
        // user_id is NOT in custom field list, so it stays as-is
        self::assertSame('user-42', $result->context['user_id']);
    }

    #[Test]
    public function skipsNonStringValues(): void
    {
        $entry = $this->createEntry([
            'user_id' => 42,
        ]);

        $result = $this->formatter->format($entry);

        self::assertSame(42, $result->context['user_id']);
    }

    #[Test]
    public function returnsNewLogEntryInstance(): void
    {
        $entry = $this->createEntry(['email' => 'test@test.com']);

        $result = $this->formatter->format($entry);

        self::assertNotSame($entry, $result);
        self::assertSame($entry->level, $result->level);
        self::assertSame($entry->message, $result->message);
        self::assertSame($entry->channel, $result->channel);
        self::assertSame($entry->timestamp, $result->timestamp);
    }

    #[Test]
    public function rejectsEmptyHmacKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GdprLogFormatter('');
    }

    #[Test]
    public function rejectsHmacKeyShorterThanMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 15 bytes — one short of the 16-byte keyed-hash minimum.
        new GdprLogFormatter('123456789012345');
    }

    #[Test]
    public function pseudonymHasExpectedFormat(): void
    {
        $entry = $this->createEntry(['email' => 'test@example.com']);

        $result = $this->formatter->format($entry);

        $email = $result->context['email'];
        self::assertIsString($email);
        // pseudonym_ prefix + 16 hex chars = 26 chars total
        self::assertMatchesRegularExpression('/^pseudonym_[a-f0-9]{16}$/', $email);
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
