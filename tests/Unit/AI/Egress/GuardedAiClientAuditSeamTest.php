<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Audit\Internal\PendingEgressDecision;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Egress\AiDestination;
use Pulsar\AI\Egress\AiEgressDecision;
use Pulsar\AI\Egress\AiEgressPolicy;
use Pulsar\AI\Egress\GuardedAiClient;
use Pulsar\AI\Exception\AiEgressRefusedException;
use Pulsar\AI\Provider\AnthropicProvider;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\SensitivePatternRegistry;
use Pulsar\Tests\Unit\AI\Egress\Support\RecordingEgressObserver;
use Pulsar\Tests\Unit\AI\Egress\Support\RecordingHttpClient;
use Pulsar\Tests\Unit\AI\Egress\Support\RecordingStreamTransport;
use Pulsar\Tests\Unit\AI\Egress\Support\WireRecorder;

/**
 * The half of the boundary that faces the auditing decorator.
 *
 * `AuditingAiClient` wraps OUTSIDE this guard and takes the decision from a
 * one-slot channel immediately after the inner call returns or throws. If the
 * guard never reports, every record says the egress decision was `not_observed`
 * and a refusal — the event most worth having — is filed as a plain provider
 * error. So the reporting is asserted here directly against the shipped slot,
 * `PendingEgressDecision`, rather than against a double that could agree with a
 * mistake.
 *
 * The clean case is the one most easily forgotten: a guard silent on the calls it
 * passed makes a guarded deployment look, in the trail, exactly like one with no
 * egress control at all.
 */
#[CoversClass(GuardedAiClient::class)]
#[CoversClass(AiEgressDecision::class)]
final class GuardedAiClientAuditSeamTest extends TestCase
{
    private const string SSN = '123-45-6789';

    #[Test]
    public function aCleanCallIsReportedAsInspectedAndPassedRatherThanNotObserved(): void
    {
        $slot = new PendingEgressDecision();

        $this->guard($slot, DlpAction::Block)->chat([ChatMessage::user('Summarise the ward rota.')]);

        $decision = $slot->takeEgressDecision();

        self::assertNotNull($decision, 'a guarded call was recorded as "egress decision not observed"');
        self::assertFalse($decision->refused);
        self::assertFalse($decision->redactionApplied);
    }

    #[Test]
    public function aRefusalReachesTheAuditSeamWithAStableReasonCode(): void
    {
        $slot = new PendingEgressDecision();

        try {
            $this->guard($slot, DlpAction::Block)->chat([ChatMessage::user('SSN ' . self::SSN)]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException) {
            // The refusal itself is asserted elsewhere; what matters here is the record.
        }

        $decision = $slot->takeEgressDecision();

        self::assertNotNull($decision);
        self::assertTrue($decision->refused);
        self::assertSame(AiEgressDecision::REASON_SENSITIVE_DATA_BLOCKED, $decision->refusalReason);
        self::assertSame(['ssn'], $decision->categories);
    }

    #[Test]
    public function aRedactionReachesTheAuditSeamWithItsSpanCount(): void
    {
        $slot = new PendingEgressDecision();

        $this->guard($slot, DlpAction::Redact)->chat([ChatMessage::user('SSN ' . self::SSN)]);

        $decision = $slot->takeEgressDecision();

        self::assertNotNull($decision);
        self::assertTrue($decision->redactionApplied);
        self::assertSame(1, $decision->redactedSpanCount);
        self::assertSame(['ssn'], $decision->categories);
    }

    #[Test]
    public function aForbiddenDestinationIsReportedBeforeAnythingIsSent(): void
    {
        $slot = new PendingEgressDecision();

        try {
            $this->guard($slot, DlpAction::Block, new AiEgressPolicy(allowedHosts: ['localhost']))
                ->chat([ChatMessage::user('Summarise the ward rota.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException) {
            // As above.
        }

        $decision = $slot->takeEgressDecision();

        self::assertNotNull($decision);
        self::assertSame(AiEgressDecision::REASON_DESTINATION_NOT_PERMITTED, $decision->refusalReason);
    }

    #[Test]
    public function anUnclassifiedPayloadIsReportedAsSuchAndNotAsAPass(): void
    {
        $slot = new PendingEgressDecision();

        try {
            $this->guard(
                $slot,
                DlpAction::Block,
                null,
                new SensitivePatternRegistry(new DlpConfig(enabled: false)),
            )->chat([ChatMessage::user('Perfectly ordinary text.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException) {
            // As above.
        }

        $decision = $slot->takeEgressDecision();

        self::assertNotNull($decision);
        self::assertTrue($decision->refused);
        self::assertSame(AiEgressDecision::REASON_UNCLASSIFIED_PAYLOAD, $decision->refusalReason);
    }

    #[Test]
    public function theStreamedPathReportsIntoTheSameSlot(): void
    {
        $slot = new PendingEgressDecision();
        $recorder = new WireRecorder();
        $transport = new RecordingStreamTransport($recorder);

        $guard = new GuardedAiClient(
            inner: new AnthropicProvider(
                apiKey: 'test-key',
                baseUrl: 'https://api.anthropic.com/v1',
                streamTransport: $transport,
            ),
            destination: AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
            policy: new AiEgressPolicy(
                allowedHosts: ['api.anthropic.com'],
                onSensitiveData: DlpAction::Block,
            ),
            classifier: new SensitivePatternRegistry(new DlpConfig(enabled: true)),
            observer: new RecordingEgressObserver(),
            auditSink: $slot,
        );

        try {
            $guard->streamChat([ChatMessage::user('SSN ' . self::SSN)]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException) {
            // As above.
        }

        $decision = $slot->takeEgressDecision();

        self::assertNotNull($decision, 'a streamed refusal left no egress decision to audit');
        self::assertTrue($decision->refused);
        self::assertSame(0, $transport->callCount);
    }

    #[Test]
    public function exactlyOneDecisionIsReportedPerCall(): void
    {
        $slot = new PendingEgressDecision();
        $guard = $this->guard($slot, DlpAction::Block);

        $guard->chat([ChatMessage::user('Summarise the ward rota.')]);

        self::assertNotNull($slot->takeEgressDecision());
        // Reading is destructive, so a second decision left in the slot would
        // leak forward onto the next inference and be recorded against it.
        self::assertNull($slot->takeEgressDecision());
    }

    private function guard(
        PendingEgressDecision $slot,
        DlpAction $onSensitiveData,
        ?AiEgressPolicy $policy = null,
        ?SensitivePatternRegistry $classifier = null,
    ): GuardedAiClient {
        return new GuardedAiClient(
            inner: new AnthropicProvider(
                apiKey: 'test-key',
                baseUrl: 'https://api.anthropic.com/v1',
                httpClient: new RecordingHttpClient(
                    new WireRecorder(),
                    '{"id":"m","model":"claude-sonnet-4-6","content":[{"type":"text","text":"ok"}],'
                    . '"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}',
                ),
            ),
            destination: AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
            policy: $policy ?? new AiEgressPolicy(
                allowedHosts: ['api.anthropic.com'],
                onSensitiveData: $onSensitiveData,
            ),
            classifier: $classifier ?? new SensitivePatternRegistry(new DlpConfig(enabled: true)),
            observer: new RecordingEgressObserver(),
            auditSink: $slot,
        );
    }
}
