<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Audit\AuditingAiClient;
use Pulsar\AI\Audit\Internal\PendingEgressDecision;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Egress\AiDestination;
use Pulsar\AI\Egress\AiEgressDecision;
use Pulsar\AI\Egress\AiEgressObserverInterface;
use Pulsar\AI\Egress\AiEgressPolicy;
use Pulsar\AI\Egress\GuardedAiClient;
use Pulsar\AI\Exception\AiEgressRefusedException;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\SensitivePatternRegistry;
use Pulsar\Tests\Unit\AI\Audit\Support\FakeAiClient;
use Pulsar\Tests\Unit\AI\Audit\Support\RecordingAuditSink;

use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * The two AI decorators composed the way ADR-0079 says, driven together.
 *
 * `AuditingAiClient(GuardedAiClient(Provider))`, with the real guard and the real
 * classifier — not a stub reporting whatever the test wanted. Three claims, and
 * none of them survives the composition being inverted:
 *
 *   1. A call the guard REFUSES still reaches the audit chain, marked denied and
 *      naming the guard's own reason code. A refusal that leaves no trace is the
 *      most interesting event a trail can hold and the easiest to lose, because
 *      the exception unwinds past a decorator that only records on success.
 *   2. A call the guard REDACTS is recorded as redacted, from the guard's
 *      decision rather than from anything the auditor worked out.
 *   3. Neither record carries the classified value that caused it.
 */
#[CoversClass(AuditingAiClient::class)]
#[CoversClass(PendingEgressDecision::class)]
final class AuditedEgressCompositionTest extends TestCase
{
    /** Matches the framework's shipped credit-card pattern. */
    private const string CARD = '4111 1111 1111 1111';

    private RecordingAuditSink $sink;

    private AuditLogger $auditLogger;

    #[Override]
    protected function setUp(): void
    {
        $this->sink = new RecordingAuditSink();
        $this->auditLogger = new AuditLogger($this->sink, random_bytes(32));
    }

    #[Test]
    public function aCallTheGuardRefusesStillReachesTheAuditChainMarkedDenied(): void
    {
        $client = $this->compose(DlpAction::Block);

        try {
            $client->chat([ChatMessage::user('Charge card ' . self::CARD . ' for the invoice.')]);
            self::fail('the guard should have refused the call');
        } catch (AiEgressRefusedException) {
            // Expected: refused before the transport, recorded on the way out.
        }

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditingAiClient::ACTION, $entry->action);
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame(AuditingAiClient::EGRESS_REFUSED, $entry->metadata['ai_egress']);
        self::assertTrue($entry->metadata['ai_egress_refused']);
        self::assertSame(AiEgressDecision::REASON_SENSITIVE_DATA_BLOCKED, $entry->metadata['ai_refusal_reason']);
        $categories = $entry->metadata['ai_redaction_categories'];
        self::assertIsArray($categories);
        self::assertContains('credit_card', $categories);

        // The record names the kind of data, never the datum.
        self::assertStringNotContainsString('4111', $this->rendered($entry->metadata));
    }

    #[Test]
    public function aCallTheGuardRedactsIsRecordedAsRedactedFromTheGuardsOwnDecision(): void
    {
        $client = $this->compose(DlpAction::Redact);

        $client->chat([ChatMessage::user('Charge card ' . self::CARD . ' for the invoice.')]);

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditOutcome::Success, $entry->outcome);
        self::assertSame(AuditingAiClient::EGRESS_REDACTED, $entry->metadata['ai_egress']);
        self::assertTrue($entry->metadata['ai_redaction_applied']);
        self::assertGreaterThanOrEqual(1, $entry->metadata['ai_redaction_spans']);
        self::assertStringNotContainsString('4111', $this->rendered($entry->metadata));
    }

    #[Test]
    public function aCleanCallThroughTheGuardIsRecordedAsInspectedAndPassed(): void
    {
        $client = $this->compose(DlpAction::Block);

        $client->chat([ChatMessage::user('Summarise the quarterly report.')]);

        // The guard reports its clean calls to the audit channel even though it
        // keeps them off the observer, which is what lets the trail distinguish
        // "examined and passed" from "nobody looked". The auditor copies that
        // distinction rather than deriving one.
        $metadata = $this->sink->entries[0]->metadata;
        self::assertSame(AuditingAiClient::EGRESS_PASSED, $metadata['ai_egress']);
        self::assertFalse($metadata['ai_redaction_applied']);
        self::assertFalse($metadata['ai_egress_refused']);
    }

    /**
     * `AuditingAiClient(GuardedAiClient(Provider))` — the order ADR-0079 fixes,
     * with the audit channel the guard reports into wired to the auditor that
     * reads it.
     */
    private function compose(DlpAction $onSensitiveData): AuditingAiClient
    {
        $channel = new PendingEgressDecision();

        $guarded = new GuardedAiClient(
            new FakeAiClient('anthropic'),
            AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
            new AiEgressPolicy(allowedHosts: ['api.anthropic.com'], onSensitiveData: $onSensitiveData),
            new SensitivePatternRegistry(new DlpConfig()),
            new class implements AiEgressObserverInterface {
                #[Override]
                public function decided(AiEgressDecision $decision): void
                {
                    // The guard requires an observer; this composition asserts on
                    // the audit trail rather than on the observer.
                }
            },
            $channel,
        );

        return new AuditingAiClient($guarded, $this->auditLogger, random_bytes(32), $channel);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function rendered(array $metadata): string
    {
        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
}
