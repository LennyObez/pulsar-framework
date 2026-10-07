<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Audit\AuditingAiClient;
use Pulsar\AI\Audit\EgressDecision;
use Pulsar\AI\Audit\InferenceOperation;
use Pulsar\AI\Audit\Internal\PendingEgressDecision;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Embedding\EmbeddingVector;
use Pulsar\AI\ToolCall;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Tests\Unit\AI\Audit\Support\FakeAiClient;
use Pulsar\Tests\Unit\AI\Audit\Support\RecordingAuditSink;
use Pulsar\Tests\Unit\AI\Audit\Support\SingleKeyRing;
use RuntimeException;

use function json_encode;
use function random_bytes;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Every inference reaches the HMAC chain, and none of them takes the content
 * along.
 *
 * The audit logger under test is the REAL {@see AuditLogger} over a recording
 * sink, not a mock of the interface. Two of the properties asserted here —
 * "exactly one CHAINED entry" and "the chain still verifies afterwards" — are
 * properties of the chain, and a mock would assert only that a method was called.
 */
#[CoversClass(AuditingAiClient::class)]
final class AuditingAiClientTest extends TestCase
{
    private const string PROMPT = 'Patient Jane Moreau, NHS 943 476 5919, presents with chest pain.';

    private string $auditKey;

    private string $digestKey;

    private RecordingAuditSink $sink;

    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->auditKey = random_bytes(32);
        $this->digestKey = random_bytes(32);
        $this->sink = new RecordingAuditSink();
        $this->auditLogger = new AuditLogger($this->sink, $this->auditKey);
    }

    #[Test]
    public function aChatCallProducesExactlyOneChainedEntry(): void
    {
        $client = $this->auditing(new FakeAiClient('anthropic'));

        $client->chat([ChatMessage::user('hello')]);

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditingAiClient::ACTION, $entry->action);
        self::assertSame(AuditEvent::Communication, $entry->event);
        self::assertSame(AuditOutcome::Success, $entry->outcome);
        self::assertSame('system:' . AuditingAiClient::ACTOR_COMPONENT, $entry->actor);
        self::assertSame(InferenceOperation::Chat->value, $entry->metadata['ai_operation']);
        self::assertSame('anthropic', $entry->metadata['ai_provider']);

        // Chained: the first entry links to the logger's seed, not to nothing.
        self::assertSame(
            Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey),
            $entry->previousHmac,
        );
    }

    #[Test]
    public function theEntryDoesNotCarryThePromptOrTheCompletionInTheClear(): void
    {
        $inner = new FakeAiClient();
        $inner->response = new AiResponse(
            content: 'Recommend admission for Jane Moreau under NHS 943 476 5919.',
            inputTokens: 41,
            outputTokens: 17,
            finishReason: 'stop',
            model: 'claude-sonnet-4-6',
        );

        $this->auditing($inner)->chat(
            [ChatMessage::user(self::PROMPT)],
            new AiRequestOptions(systemPrompt: 'You are a triage assistant for Jane Moreau.'),
        );

        $rendered = $this->render($this->sink->entries[0]);

        self::assertStringNotContainsString('Jane Moreau', $rendered);
        self::assertStringNotContainsString('943 476 5919', $rendered);
        self::assertStringNotContainsString('Recommend admission', $rendered);
        self::assertStringNotContainsString('triage assistant', $rendered);

        // What stands in for it: a keyed digest and a length, both present.
        $metadata = $this->sink->entries[0]->metadata;
        self::assertSame(strlen(self::PROMPT), $metadata['ai_prompt_chars']);
        self::assertSame(strlen($inner->response->content), $metadata['ai_completion_chars']);
        self::assertIsString($metadata['ai_prompt_digest']);
        self::assertSame(64, strlen($metadata['ai_prompt_digest']));
    }

    #[Test]
    public function theDigestIsKeyedSoTheAuditFileAloneDoesNotRevealThePrompt(): void
    {
        $first = $this->auditing(new FakeAiClient());
        $first->chat([ChatMessage::user(self::PROMPT)]);

        $otherKeySink = new RecordingAuditSink();
        $second = new AuditingAiClient(
            new FakeAiClient(),
            new AuditLogger($otherKeySink, $this->auditKey),
            random_bytes(32),
        );
        $second->chat([ChatMessage::user(self::PROMPT)]);

        // Same prompt, different digest key: an attacker holding only the file
        // cannot recompute the digest of a guessed prompt.
        self::assertNotSame(
            $this->sink->entries[0]->metadata['ai_prompt_digest'],
            $otherKeySink->entries[0]->metadata['ai_prompt_digest'],
        );
    }

    #[Test]
    public function theSamePromptDigestsAlikeSoTwoEntriesCanBeCorrelated(): void
    {
        $client = $this->auditing(new FakeAiClient());

        $client->chat([ChatMessage::user(self::PROMPT)]);
        $client->chat([ChatMessage::user(self::PROMPT)]);
        $client->chat([ChatMessage::user('something else entirely')]);

        self::assertSame(
            $this->sink->entries[0]->metadata['ai_prompt_digest'],
            $this->sink->entries[1]->metadata['ai_prompt_digest'],
        );
        self::assertNotSame(
            $this->sink->entries[0]->metadata['ai_prompt_digest'],
            $this->sink->entries[2]->metadata['ai_prompt_digest'],
        );
    }

    #[Test]
    public function twoInputsThatConcatenateAlikeDigestDifferently(): void
    {
        $client = $this->auditing(new FakeAiClient());

        // Same bytes once the separator is written, different documents. A digest
        // that let these collide would be a false match in an investigation, which
        // is why the rendering is length-prefixed rather than merely joined.
        $client->embed(["a\nb"]);
        $client->embed(['a', 'b']);

        self::assertNotSame(
            $this->sink->entries[0]->metadata['ai_prompt_digest'],
            $this->sink->entries[1]->metadata['ai_prompt_digest'],
        );
    }

    #[Test]
    public function aRefusedCallIsRecordedTooAndMarkedRefused(): void
    {
        $inner = new FakeAiClient();
        $inner->failWith = new RuntimeException('egress control refused the request');

        $pending = new PendingEgressDecision();
        $pending->report(EgressDecision::refusal('unclassified_personal_data', ['phi', 'pii.nhs_number']));

        $client = new AuditingAiClient($inner, $this->auditLogger, $this->digestKey, $pending);

        try {
            $client->chat([ChatMessage::user(self::PROMPT)]);
            self::fail('the refusal should have reached the caller');
        } catch (RuntimeException) {
            // Expected: the auditor records and rethrows.
        }

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertTrue($entry->metadata['ai_egress_refused']);
        self::assertSame('unclassified_personal_data', $entry->metadata['ai_refusal_reason']);
        self::assertSame(['phi', 'pii.nhs_number'], $entry->metadata['ai_redaction_categories']);
    }

    #[Test]
    public function aRedactionThatFiredIsRecordedFromTheControlsOwnDecision(): void
    {
        $pending = new PendingEgressDecision();
        $pending->report(EgressDecision::redacted(3, ['pii.email']));

        $client = new AuditingAiClient(new FakeAiClient(), $this->auditLogger, $this->digestKey, $pending);
        $client->chat([ChatMessage::user('hello')]);

        $metadata = $this->sink->entries[0]->metadata;
        self::assertSame(AuditingAiClient::EGRESS_REDACTED, $metadata['ai_egress']);
        self::assertTrue($metadata['ai_redaction_applied']);
        self::assertSame(3, $metadata['ai_redaction_spans']);
        self::assertFalse($metadata['ai_egress_refused']);
    }

    #[Test]
    public function withNoEgressControlTheDecisionIsRecordedAsNotObservedRatherThanAsAPass(): void
    {
        $this->auditing(new FakeAiClient())->chat([ChatMessage::user('hello')]);

        $metadata = $this->sink->entries[0]->metadata;
        self::assertSame(AuditingAiClient::EGRESS_NOT_OBSERVED, $metadata['ai_egress']);
        self::assertArrayNotHasKey('ai_redaction_applied', $metadata);
    }

    #[Test]
    public function aComposedControlThatReportsNothingIsRecordedApartFromNoControlAtAll(): void
    {
        // A channel is composed, and the control found nothing worth reporting.
        // That is a different fact from "nobody looked", and the two must not
        // collapse onto one value.
        $client = new AuditingAiClient(
            new FakeAiClient(),
            $this->auditLogger,
            $this->digestKey,
            new PendingEgressDecision(),
        );

        $client->chat([ChatMessage::user('hello')]);

        self::assertSame(AuditingAiClient::EGRESS_NO_FINDING, $this->sink->entries[0]->metadata['ai_egress']);
    }

    #[Test]
    public function anInspectedAndForwardedPayloadIsRecordedAsPassedNotMerelyObserved(): void
    {
        $pending = new PendingEgressDecision();
        $pending->report(EgressDecision::passed());

        $client = new AuditingAiClient(new FakeAiClient(), $this->auditLogger, $this->digestKey, $pending);
        $client->chat([ChatMessage::user('hello')]);

        $metadata = $this->sink->entries[0]->metadata;
        self::assertSame(AuditingAiClient::EGRESS_PASSED, $metadata['ai_egress']);
        self::assertFalse($metadata['ai_redaction_applied']);
    }

    #[Test]
    public function aDecisionIsAttributedToOneCallAndNeverInheritedByTheNext(): void
    {
        $pending = new PendingEgressDecision();
        $pending->report(EgressDecision::redacted(1, ['pii.email']));

        $client = new AuditingAiClient(new FakeAiClient(), $this->auditLogger, $this->digestKey, $pending);

        $client->chat([ChatMessage::user('first')]);
        $client->chat([ChatMessage::user('second')]);

        self::assertSame(AuditingAiClient::EGRESS_REDACTED, $this->sink->entries[0]->metadata['ai_egress']);
        self::assertSame(AuditingAiClient::EGRESS_NO_FINDING, $this->sink->entries[1]->metadata['ai_egress']);
    }

    #[Test]
    public function aProviderFailureIsRecordedAndItsMessageIsNotCopiedIntoTheTrail(): void
    {
        $inner = new FakeAiClient();
        // Providers echo the offending request back in their errors. This is the
        // shape that would put the prompt into the audit file.
        $inner->failWith = new RuntimeException('400 invalid_request: ' . self::PROMPT);

        $client = $this->auditing($inner);

        try {
            $client->chat([ChatMessage::user(self::PROMPT)]);
            self::fail('the provider failure should have reached the caller');
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditOutcome::Error, $entry->outcome);
        self::assertSame(RuntimeException::class, $entry->metadata['ai_error']);
        self::assertStringNotContainsString('Jane Moreau', $this->render($entry));
        self::assertStringNotContainsString('invalid_request', $this->render($entry));
    }

    #[Test]
    public function tokenCountsComeFromTheProvidersOwnAccounting(): void
    {
        $inner = new FakeAiClient();
        $inner->response = new AiResponse('answer', 411, 92, 'length', [], 'claude-sonnet-4-6-20260514');

        $this->auditing($inner)->chat(
            [ChatMessage::user('hello')],
            new AiRequestOptions(model: 'claude-sonnet-4-6'),
        );

        $metadata = $this->sink->entries[0]->metadata;
        self::assertSame(411, $metadata['ai_input_tokens']);
        self::assertSame(92, $metadata['ai_output_tokens']);
        self::assertSame(503, $metadata['ai_total_tokens']);
        self::assertSame('length', $metadata['ai_finish_reason']);
        // The model asked for and the model that answered are kept apart.
        self::assertSame('claude-sonnet-4-6', $metadata['ai_model_requested']);
        self::assertSame('claude-sonnet-4-6-20260514', $metadata['ai_model_reported']);
        self::assertSame('fake:claude-sonnet-4-6-20260514', $this->sink->entries[0]->resource);
    }

    #[Test]
    public function latencyIsMeasuredAcrossTheCall(): void
    {
        $inner = new FakeAiClient();
        $inner->delayUs = 4000;

        $this->auditing($inner)->chat([ChatMessage::user('hello')]);

        $latency = $this->sink->entries[0]->metadata['ai_latency_us'];
        self::assertIsInt($latency);
        self::assertGreaterThanOrEqual(3000, $latency);
    }

    #[Test]
    public function toolNamesAreRecordedAndToolArgumentsAreNot(): void
    {
        $inner = new FakeAiClient();
        $inner->response = new AiResponse(
            content: '',
            inputTokens: 10,
            outputTokens: 4,
            finishReason: 'tool_use',
            toolCalls: [new ToolCall('call_1', 'transfer_funds', ['iban' => 'GB33BUKB20201555555555', 'amount' => 9500])],
            model: 'gpt-4o',
        );

        $this->auditing($inner)->chat([ChatMessage::user('pay the invoice')]);

        $entry = $this->sink->entries[0];
        self::assertSame(1, $entry->metadata['ai_tool_call_count']);
        self::assertSame(['transfer_funds'], $entry->metadata['ai_tool_names']);
        self::assertStringNotContainsString('GB33BUKB', $this->render($entry));
        self::assertStringNotContainsString('9500', $this->render($entry));
    }

    #[Test]
    public function embeddingInputsAreRecordedByShapeAndNotByContent(): void
    {
        $inner = new FakeAiClient();
        $inner->embedding = new EmbeddingResult([new EmbeddingVector([0.1, 0.2], 0)], 88, 'text-embedding-3-large');

        $this->auditing($inner)->embed([self::PROMPT, 'second document']);

        $entry = $this->sink->entries[0];
        self::assertSame(InferenceOperation::Embed->value, $entry->metadata['ai_operation']);
        self::assertSame(2, $entry->metadata['ai_input_count']);
        self::assertSame(88, $entry->metadata['ai_total_tokens']);
        self::assertSame(1, $entry->metadata['ai_embedding_count']);
        self::assertStringNotContainsString('Jane Moreau', $this->render($entry));
    }

    #[Test]
    public function aStructuredOutputCallRecordsTheSchemaAsADigestNotAsItsPropertyNames(): void
    {
        $this->auditing(new FakeAiClient())->structuredOutput(
            self::PROMPT,
            ['type' => 'object', 'properties' => ['hiv_status' => ['type' => 'string']]],
        );

        $entry = $this->sink->entries[0];
        self::assertSame(InferenceOperation::StructuredOutput->value, $entry->metadata['ai_operation']);
        self::assertIsString($entry->metadata['ai_schema_digest']);
        self::assertStringNotContainsString('hiv_status', $this->render($entry));
    }

    #[Test]
    public function theChainStillVerifiesAfterInferenceEntries(): void
    {
        $client = $this->auditing(new FakeAiClient());

        $client->chat([ChatMessage::user('one')]);
        $client->complete('two');
        $client->embed(['three']);

        self::assertCount(3, $this->sink->entries);

        $result = new AuditChainVerifier(new SingleKeyRing($this->auditKey))->verifyChain(
            $this->sink->entries,
            Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey),
        );

        self::assertTrue($result->valid);
    }

    #[Test]
    public function aDigestKeyTooShortForKeyedHashingIsRefusedAtCompositionTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditingAiClient(new FakeAiClient(), $this->auditLogger, substr($this->digestKey, 0, 8));
    }

    #[Test]
    public function theProviderNameIsPassedThrough(): void
    {
        self::assertSame('ollama', $this->auditing(new FakeAiClient('ollama'))->providerName());
    }

    private function auditing(FakeAiClient $inner): AuditingAiClient
    {
        return new AuditingAiClient($inner, $this->auditLogger, $this->digestKey);
    }

    /**
     * The entry as it reaches disk, which is where a leak would show up.
     */
    private function render(AuditEntry $entry): string
    {
        return json_encode($entry->toArray(), JSON_THROW_ON_ERROR);
    }
}
