<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Audit\AuditingAiClient;
use Pulsar\AI\Audit\EgressDecision;
use Pulsar\AI\Audit\InferenceOperation;
use Pulsar\AI\Audit\Internal\PendingEgressDecision;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\AiStreamDelta;
use Pulsar\AI\Streaming\AiTokenUsage;
use Pulsar\AI\Streaming\ToolCallDelta;
use Pulsar\AI\ToolCall;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Tests\Unit\AI\Audit\Support\FakeAiClient;
use Pulsar\Tests\Unit\AI\Audit\Support\RecordingAuditSink;
use Pulsar\Tests\Unit\AI\Audit\Support\SingleKeyRing;

use function gc_collect_cycles;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * A streamed inference is recorded once, at the end, however it ends.
 *
 * Usage counts are only final when the stream completes, so the record cannot be
 * written when `streamChat()` returns. That leaves three endings, and all three
 * are asserted here — read to completion, broken mid-flight, and abandoned by a
 * consumer that stopped iterating. The third is the one an audit trail loses
 * quietly, because it produces neither a response nor an exception: only the
 * destruction of a suspended generator, and the `finally` that PHP runs then.
 */
#[CoversClass(AuditingAiClient::class)]
final class AuditingAiClientStreamTest extends TestCase
{
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
    public function aStreamReadToCompletionIsRecordedOnceWithTheFinalTokenCounts(): void
    {
        $inner = new FakeAiClient('anthropic');
        $inner->deltas = [
            AiStreamDelta::usage(new AiTokenUsage(inputTokens: 120)),
            AiStreamDelta::text('Hel'),
            AiStreamDelta::text('lo'),
            AiStreamDelta::usage(new AiTokenUsage(outputTokens: 7)),
            AiStreamDelta::finish('stop'),
        ];
        $inner->response = new AiResponse('Hello', 120, 7, 'stop', [], 'claude-sonnet-4-6');

        $stream = $this->auditing($inner)->streamChat([ChatMessage::user('hi')]);

        $seen = [];

        foreach ($stream as $delta) {
            $seen[] = $delta;
        }

        // Nothing is recorded until the stream ends: the counts do not exist yet.
        self::assertCount(5, $seen);
        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(InferenceOperation::StreamChat->value, $entry->metadata['ai_operation']);
        self::assertSame(AuditOutcome::Success, $entry->outcome);
        self::assertTrue($entry->metadata['ai_stream_complete']);
        self::assertFalse($entry->metadata['ai_stream_abandoned']);
        self::assertSame(5, $entry->metadata['ai_stream_deltas']);
        self::assertSame(120, $entry->metadata['ai_input_tokens']);
        self::assertSame(7, $entry->metadata['ai_output_tokens']);
        self::assertSame('stop', $entry->metadata['ai_finish_reason']);
    }

    #[Test]
    public function callingResponseWithoutIteratingStillRecordsExactlyOnce(): void
    {
        $inner = new FakeAiClient();
        $inner->deltas = [AiStreamDelta::text('hi'), AiStreamDelta::finish('stop')];
        $inner->response = new AiResponse('hi', 4, 1, 'stop', [], 'm');

        $response = $this->auditing($inner)->streamChat([ChatMessage::user('hi')])->response();

        self::assertSame('hi', $response->content);
        self::assertCount(1, $this->sink->entries);
        self::assertTrue($this->sink->entries[0]->metadata['ai_stream_complete']);
    }

    #[Test]
    public function aStreamThatDiesHalfWayIsRecordedAsIncomplete(): void
    {
        $inner = new FakeAiClient('openai');
        $inner->deltas = [
            AiStreamDelta::usage(new AiTokenUsage(inputTokens: 55)),
            AiStreamDelta::text('partial'),
        ];
        $inner->streamFailsWith = AiStreamException::truncated('openai', 'partial');

        $stream = $this->auditing($inner)->streamChat([ChatMessage::user('hi')]);

        try {
            foreach ($stream as $ignored) {
                // Drain until the provider drops the connection.
            }
            self::fail('the truncated stream should have reached the caller');
        } catch (AiStreamException) {
            // Expected: recorded, then rethrown.
        }

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditOutcome::Error, $entry->outcome);
        self::assertFalse($entry->metadata['ai_stream_complete']);
        self::assertFalse($entry->metadata['ai_stream_abandoned']);
        self::assertSame(2, $entry->metadata['ai_stream_deltas']);
        self::assertSame(AiStreamException::class, $entry->metadata['ai_error']);
        // The accounting that did arrive is kept; the half that never did is
        // absent rather than recorded as zero.
        self::assertSame(55, $entry->metadata['ai_input_tokens']);
        self::assertArrayNotHasKey('ai_output_tokens', $entry->metadata);
        self::assertArrayNotHasKey('ai_finish_reason', $entry->metadata);
    }

    #[Test]
    public function aStreamAbandonedByItsConsumerIsStillRecordedAsIncomplete(): void
    {
        $inner = new FakeAiClient();
        $inner->deltas = [
            AiStreamDelta::text('one'),
            AiStreamDelta::text('two'),
            AiStreamDelta::text('three'),
            AiStreamDelta::finish('stop'),
        ];

        $stream = $this->auditing($inner)->streamChat([ChatMessage::user('hi')]);

        foreach ($stream as $ignored) {
            break;
        }

        // Nothing has ended the stream yet; the record is written when the
        // suspended generator is destroyed.
        self::assertCount(0, $this->sink->entries);

        unset($stream);
        gc_collect_cycles();

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertFalse($entry->metadata['ai_stream_complete']);
        self::assertTrue($entry->metadata['ai_stream_abandoned']);
        self::assertSame(1, $entry->metadata['ai_stream_deltas']);
        self::assertSame(3, $entry->metadata['ai_completion_chars']);
        self::assertArrayNotHasKey('ai_error', $entry->metadata);
    }

    #[Test]
    public function twoStreamsOpenAtOnceDoNotSwapEachOthersEgressDecisions(): void
    {
        $channel = new PendingEgressDecision();

        $first = new FakeAiClient();
        $first->deltas = [AiStreamDelta::text('a'), AiStreamDelta::finish('stop')];
        $first->response = new AiResponse('a', 1, 1, 'stop', [], 'm');

        $second = new FakeAiClient();
        $second->deltas = [AiStreamDelta::text('b'), AiStreamDelta::finish('stop')];
        $second->response = new AiResponse('b', 1, 1, 'stop', [], 'm');

        // An egress control decides when the CALL is made — the request is what it
        // inspects — while the record is written when the stream ENDS. Opening two
        // streams before finishing either is what makes the difference visible.
        $channel->report(EgressDecision::redacted(2, ['pii.email']));
        $streamA = new AuditingAiClient($first, $this->auditLogger, $this->digestKey, $channel)
            ->streamChat([ChatMessage::user('a')]);

        $channel->report(EgressDecision::refusal('pan_detected', ['pci.pan']));
        $streamB = new AuditingAiClient($second, $this->auditLogger, $this->digestKey, $channel)
            ->streamChat([ChatMessage::user('b')]);

        self::assertSame('a', $streamA->response()->content);
        self::assertSame('b', $streamB->response()->content);

        self::assertSame(AuditingAiClient::EGRESS_REDACTED, $this->sink->entries[0]->metadata['ai_egress']);
        self::assertSame(AuditingAiClient::EGRESS_REFUSED, $this->sink->entries[1]->metadata['ai_egress']);
    }

    #[Test]
    public function aStreamRefusedBeforeItOpensIsRecordedAsDenied(): void
    {
        $inner = new FakeAiClient();
        $inner->failWith = AiStreamException::providerError('openai', 'refused by egress control');

        $pending = new PendingEgressDecision();
        $pending->report(EgressDecision::refusal('pan_detected', ['pci.pan']));

        $client = new AuditingAiClient($inner, $this->auditLogger, $this->digestKey, $pending);

        try {
            $client->streamChat([ChatMessage::user('card 4111 1111 1111 1111')]);
            self::fail('the refusal should have reached the caller');
        } catch (AiStreamException) {
            // Expected.
        }

        self::assertCount(1, $this->sink->entries);

        $entry = $this->sink->entries[0];
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame('pan_detected', $entry->metadata['ai_refusal_reason']);
        self::assertFalse($entry->metadata['ai_stream_complete']);
        self::assertSame(0, $entry->metadata['ai_stream_deltas']);
        self::assertStringNotContainsString('4111', $this->render($entry));
    }

    #[Test]
    public function toolNamesReachedForOverAStreamAreRecordedWithoutTheirArguments(): void
    {
        $inner = new FakeAiClient();
        $inner->deltas = [
            AiStreamDelta::toolCall(new ToolCallDelta(0, 'call_1', 'transfer_funds')),
            AiStreamDelta::toolCall(new ToolCallDelta(0, argumentsFragment: '{"iban":"GB33BUKB2020')),
            AiStreamDelta::toolCall(new ToolCallDelta(0, argumentsFragment: '1555555555"}')),
            AiStreamDelta::finish('tool_use'),
        ];
        $inner->response = new AiResponse(
            '',
            10,
            4,
            'tool_use',
            [new ToolCall('call_1', 'transfer_funds', ['iban' => 'GB33BUKB20201555555555'])],
            'gpt-4o',
        );

        $stream = $this->auditing($inner)->streamChat([ChatMessage::user('pay it')]);
        self::assertSame('', $stream->response()->content);

        $entry = $this->sink->entries[0];
        self::assertSame(['transfer_funds'], $entry->metadata['ai_tool_names']);
        self::assertStringNotContainsString('GB33BUKB', $this->render($entry));
    }

    #[Test]
    public function aBrokenStreamRecordsToolNamesObservedBeforeItBroke(): void
    {
        $inner = new FakeAiClient();
        $inner->deltas = [
            AiStreamDelta::toolCall(new ToolCallDelta(1, 'call_2', 'close_account')),
            AiStreamDelta::toolCall(new ToolCallDelta(0, 'call_1', 'transfer_funds')),
        ];
        $inner->streamFailsWith = AiStreamException::truncated('openai', '');

        try {
            foreach ($this->auditing($inner)->streamChat([ChatMessage::user('go')]) as $delta) {
                self::assertNotNull($delta->toolCall);
            }
            self::fail('the truncated stream should have reached the caller');
        } catch (AiStreamException) {
            // Expected.
        }

        // Ordered by the provider's own fragment index, not by arrival.
        self::assertSame(['transfer_funds', 'close_account'], $this->sink->entries[0]->metadata['ai_tool_names']);
    }

    #[Test]
    public function theStreamedEntryDoesNotCarryTheConversationOrTheCompletion(): void
    {
        $inner = new FakeAiClient();
        $inner->deltas = [AiStreamDelta::text('Approve the loan for Jane Moreau.'), AiStreamDelta::finish('stop')];
        $inner->response = new AiResponse('Approve the loan for Jane Moreau.', 9, 8, 'stop', [], 'm');

        $answer = $this->auditing($inner)->streamChat([ChatMessage::user('Should Jane Moreau get the loan?')])->response();

        self::assertSame('Approve the loan for Jane Moreau.', $answer->content);

        $rendered = $this->render($this->sink->entries[0]);
        self::assertStringNotContainsString('Jane Moreau', $rendered);
        self::assertStringNotContainsString('Approve the loan', $rendered);
    }

    #[Test]
    public function theChainStillVerifiesAfterAMixOfStreamEndings(): void
    {
        $complete = new FakeAiClient();
        $complete->deltas = [AiStreamDelta::text('ok'), AiStreamDelta::finish('stop')];
        $complete->response = new AiResponse('ok', 1, 1, 'stop', [], 'm');

        self::assertSame('ok', $this->auditing($complete)->streamChat([ChatMessage::user('a')])->response()->content);

        $broken = new FakeAiClient();
        $broken->deltas = [AiStreamDelta::text('partial')];
        $broken->streamFailsWith = AiStreamException::truncated('fake', 'partial');

        try {
            foreach ($this->auditing($broken)->streamChat([ChatMessage::user('b')]) as $ignored) {
                // Drain.
            }
        } catch (AiStreamException) {
            // Expected.
        }

        $abandoned = new FakeAiClient();
        $abandoned->deltas = [AiStreamDelta::text('x'), AiStreamDelta::text('y'), AiStreamDelta::finish('stop')];
        $dropped = $this->auditing($abandoned)->streamChat([ChatMessage::user('c')]);

        foreach ($dropped as $ignored) {
            break;
        }

        unset($dropped);
        gc_collect_cycles();

        self::assertCount(3, $this->sink->entries);

        $result = new AuditChainVerifier(new SingleKeyRing($this->auditKey))->verifyChain(
            $this->sink->entries,
            Hmac::computeHex('PULSAR_AUDIT_SEED', $this->auditKey),
        );

        self::assertTrue($result->valid);
    }

    private function auditing(FakeAiClient $inner): AuditingAiClient
    {
        return new AuditingAiClient($inner, $this->auditLogger, $this->digestKey);
    }

    private function render(AuditEntry $entry): string
    {
        return json_encode($entry->toArray(), JSON_THROW_ON_ERROR);
    }
}
