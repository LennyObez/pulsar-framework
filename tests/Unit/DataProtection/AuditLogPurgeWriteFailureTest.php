<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pulsar\DataProtection\AuditLogPurge;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use RuntimeException;
use Stringable;

use function bin2hex;
use function chmod;
use function file_put_contents;
use function implode;
use function is_writable;
use function random_bytes;
use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Failure-path coverage for AuditLogPurge that must not share the temp-file
 * teardown (and unlink) of the main AuditLogPurgeTest.
 *
 * Each test uses a freshly named file under the OS temp dir, which the OS
 * reclaims — no unlink() on a per-test path.
 */
#[CoversClass(AuditLogPurge::class)]
final class AuditLogPurgeWriteFailureTest extends TestCase
{
    /**
     * Finding [4]: when the rewrite fails (unwritable target), purge() must
     * throw rather than return a non-zero count, which would falsely assert
     * to the compliance trail that the audit log was actually rewritten.
     */
    #[Test]
    public function purgeThrowsWhenLogCannotBeRewritten(): void
    {
        $path = $this->freshTempFile();
        file_put_contents(
            $path,
            "{\"timestamp\":\"2020-01-01T00:00:00+00:00\",\"event\":\"expired\"}\n",
            LOCK_EX,
        );

        chmod($path, 0o444);

        if (is_writable($path)) {
            // Privileged user (e.g. root in CI) bypasses the read-only bit, so
            // the write would succeed and the failure path is unreachable.
            chmod($path, 0o644);
            self::markTestSkipped('Cannot make file read-only for the current user.');
        }

        $purge = new AuditLogPurge($path);
        $policy = new DefaultRetentionPolicy('audit_logs', 365, 'GDPR Art. 17');

        // file_put_contents emits a PHP warning on failure (alongside its false
        // return). That warning is expected here and is not the behaviour under
        // test, so swallow it for this call only — failOnWarning would otherwise
        // mask the assertion. Production code intentionally does not suppress it.
        set_error_handler(static fn(): bool => true);

        try {
            $purge->purge($policy);
            self::fail('Expected RuntimeException on unwritable audit log.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        } finally {
            restore_error_handler();
            // Restore writability so the OS temp sweeper can reclaim the file.
            chmod($path, 0o644);
        }
    }

    /**
     * Finding [8]: a malformed JSON line is not retained on rewrite, so it
     * would be silently lost from the audit trail. The purger must surface it
     * via the injected logger instead of destroying it without record.
     */
    #[Test]
    public function purgeWarnsWhenSkippingMalformedEntry(): void
    {
        $path = $this->freshTempFile();
        file_put_contents($path, implode("\n", [
            '{"timestamp":"2020-01-01T00:00:00+00:00","event":"expired"}',
            'this-is-not-json',
        ]) . "\n", LOCK_EX);

        $logger = new RecordingLogger();
        $purge = new AuditLogPurge($path, $logger);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        $purged = $purge->purge($policy);

        // The single valid expired entry is purged; the malformed line is
        // skipped — and that skip is reported, not silent.
        self::assertSame(1, $purged);
        self::assertCount(1, $logger->warnings);
        self::assertSame('Skipping malformed audit log entry', $logger->warnings[0]['message']);
        self::assertSame('this-is-not-json', $logger->warnings[0]['context']['line']);
    }

    /**
     * Finding [8]: with no logger injected the purger must still behave (skip
     * the malformed line) without error — the warning is simply not emitted.
     */
    #[Test]
    public function purgeSkipsMalformedEntrySilentlyWithoutLogger(): void
    {
        $path = $this->freshTempFile();
        file_put_contents($path, implode("\n", [
            '{"timestamp":"2020-01-01T00:00:00+00:00","event":"expired"}',
            'broken{',
        ]) . "\n", LOCK_EX);

        $purge = new AuditLogPurge($path);
        $policy = new DefaultRetentionPolicy('audit_logs', 365);

        self::assertSame(1, $purge->purge($policy));
    }

    private function freshTempFile(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit_purge_fail_' . bin2hex(random_bytes(8)) . '.jsonl';
    }
}

/**
 * Minimal PSR-3 logger that records warning calls for assertion.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
