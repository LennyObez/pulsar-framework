<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Egress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Egress\AiDestination;
use Pulsar\AI\Egress\AiEgressDecision;
use Pulsar\AI\Egress\AiEgressOutcome;
use Pulsar\AI\Egress\AiEgressPolicy;
use Pulsar\AI\Egress\GuardedAiClient;
use Pulsar\AI\Exception\AiEgressRefusedException;
use Pulsar\AI\Provider\AnthropicProvider;
use Pulsar\AI\Provider\OllamaProvider;
use Pulsar\AI\ToolDefinition;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePattern;
use Pulsar\Security\Dlp\SensitivePatternRegistry;
use Pulsar\Tests\Unit\AI\Egress\Support\RecordingEgressObserver;
use Pulsar\Tests\Unit\AI\Egress\Support\RecordingHttpClient;
use Pulsar\Tests\Unit\AI\Egress\Support\RecordingStreamTransport;
use Pulsar\Tests\Unit\AI\Egress\Support\WireRecorder;
use RuntimeException;

use function str_repeat;

/**
 * The egress boundary, driven against real providers and a recording transport.
 *
 * Every claim here is about BYTES: what the endpoint would have received. The
 * doubles sit where the socket would, so "nothing was sent" is observed as an
 * empty recorder rather than inferred from an exception type, and "the personal
 * data did not leave in the clear" is observed by searching the serialised
 * request body for the value itself.
 *
 * The unstreamed and streamed paths are asserted separately throughout, because
 * they reach the network through different objects — `HttpClientInterface` and
 * `StreamTransportInterface` — and a guard covering only the first would pass
 * every unstreamed test in this file.
 */
#[CoversClass(GuardedAiClient::class)]
#[CoversClass(AiDestination::class)]
#[CoversClass(AiEgressPolicy::class)]
#[CoversClass(AiEgressDecision::class)]
#[CoversClass(AiEgressRefusedException::class)]
final class GuardedAiClientTest extends TestCase
{
    /** A US SSN, which the shipped pattern set classifies. */
    private const string SSN = '123-45-6789';

    /** Its masked rendering under the default mask and 4-character suffix. */
    private const string MASKED_SSN = '*******6789';

    // --- Requirement: classified personal data never reaches the transport ---

    #[Test]
    public function aPayloadCarryingPersonalDataNeverReachesTheTransportInTheClear(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $guard = $this->guardOverHttp($recorder, $observer, $this->blockingPolicy());

        try {
            $guard->chat([ChatMessage::user('Patient SSN is ' . self::SSN . ', summarise the file.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('classified span', $refusal->getMessage());
        }

        // The claim is not "an exception was thrown" — it is that no bytes left.
        self::assertTrue($recorder->sentNothing(), 'the transport was handed a payload');
        self::assertFalse($recorder->anyBodyContains(self::SSN));

        $decision = $observer->only();
        self::assertNotNull($decision);
        self::assertSame(AiEgressOutcome::Refused, $decision->outcome);
        self::assertSame([SensitiveDataType::Ssn], $decision->sensitiveDataTypes());
    }

    #[Test]
    public function underRedactionTheWireCarriesTheMaskedFormAndNeverTheOriginal(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $guard = $this->guardOverHttp($recorder, $observer, $this->redactingPolicy());

        $guard->chat([ChatMessage::user('Patient SSN is ' . self::SSN . ', summarise the file.')]);

        self::assertSame(1, $recorder->requestCount());
        self::assertFalse(
            $recorder->anyBodyContains(self::SSN),
            'the unredacted value reached the wire',
        );
        self::assertStringContainsString(self::MASKED_SSN, $recorder->onlyBody());
    }

    #[Test]
    public function aRedactionIsReportedSoTheCallerCanKnowTheirPromptWasChanged(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $this->guardOverHttp($recorder, $observer, $this->redactingPolicy())
            ->chat([ChatMessage::user('Patient SSN is ' . self::SSN)]);

        $decision = $observer->only();
        self::assertNotNull($decision, 'the prompt was rewritten and nobody was told');
        self::assertSame(AiEgressOutcome::Redacted, $decision->outcome);
        self::assertSame([SensitiveDataType::Ssn], $decision->sensitiveDataTypes());
        self::assertSame('chat', $decision->operation);
    }

    #[Test]
    public function aCleanPayloadGoesOutUntouchedAndReportsNothing(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $this->guardOverHttp($recorder, $observer, $this->blockingPolicy())
            ->chat([ChatMessage::user('Summarise the ward rota.')]);

        self::assertSame(1, $recorder->requestCount());
        self::assertStringContainsString('Summarise the ward rota.', $recorder->onlyBody());
        self::assertTrue($observer->sawNothing(), 'a clean call reported a decision');
    }

    #[Test]
    public function theSystemPromptIsClassifiedToo(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $guard = $this->guardOverHttp($recorder, $observer, $this->redactingPolicy());

        $guard->chat(
            [ChatMessage::user('Summarise.')],
            new AiRequestOptions(systemPrompt: 'The patient SSN is ' . self::SSN),
        );

        // A guard that only walked the messages would ship this one verbatim.
        self::assertFalse($recorder->anyBodyContains(self::SSN));
        self::assertStringContainsString(self::MASKED_SSN, $recorder->onlyBody());
    }

    #[Test]
    public function aToolResultCarryingPersonalDataIsClassifiedLikeAnyOtherContent(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $guard = $this->guardOverHttp($recorder, $observer, $this->blockingPolicy());

        $this->expectException(AiEgressRefusedException::class);

        // The record a tool looked up is the shape most likely to carry data the
        // application never typed itself.
        $guard->chat([ChatMessage::toolResult('call_1', 'lookup returned SSN ' . self::SSN)]);
    }

    // --- Requirement: refuse when the classifier is absent -------------------

    #[Test]
    public function theCallIsRefusedWhenTheClassifierIsSwitchedOff(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // A registry over a disabled DlpConfig answers "nothing found" for every
        // input without reading a byte. That is the state of a stock deployment:
        // no wiring in the tree constructs this registry at all.
        $guard = $this->guardOverHttp(
            $recorder,
            $observer,
            $this->blockingPolicy(),
            new SensitivePatternRegistry(new DlpConfig(enabled: false)),
        );

        try {
            $guard->chat([ChatMessage::user('Perfectly ordinary text.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('did not examine the payload', $refusal->getMessage());
            self::assertStringContainsString('disabled', $refusal->getMessage());
        }

        self::assertTrue($recorder->sentNothing());
    }

    #[Test]
    public function theCallIsRefusedWhenTheClassifiersEngineGaveUp(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: true));
        // The shape an application registers and PCRE cannot finish. Before the
        // status was reported, preg_match_all()'s `false` compared as `< 1` and
        // the scan announced a clean bill of health for content it had failed to
        // read.
        $registry->register(new SensitivePattern(SensitiveDataType::Custom, '/^(?:[a-z]+)+$/'));

        $guard = $this->guardOverHttp($recorder, $observer, $this->blockingPolicy(), $registry);

        try {
            $guard->chat([ChatMessage::user(str_repeat('a', 40) . '!')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('failed', $refusal->getMessage());
        }

        self::assertTrue($recorder->sentNothing());
    }

    #[Test]
    public function theCallIsRefusedWhenAPatternValidatorThrows(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: true));
        $registry->register(new SensitivePattern(
            SensitiveDataType::Custom,
            '/\bpatient\b/i',
            static fn(string $value): bool => throw new RuntimeException('validator exploded'),
        ));

        $guard = $this->guardOverHttp($recorder, $observer, $this->blockingPolicy(), $registry);

        try {
            $guard->chat([ChatMessage::user('The patient is stable.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('validator exploded', $refusal->getMessage());
        }

        self::assertTrue($recorder->sentNothing());
    }

    // --- Requirement: destination control ------------------------------------

    #[Test]
    public function aForbiddenDestinationIsRefusedBeforeAnyTransportIsConstructed(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // The hospital case: Ollama on its own hardware is permitted, every
        // hosted endpoint is not. The payload here is spotless — the refusal is
        // about where it was going, not what it said.
        $guard = $this->guardOverHttp(
            $recorder,
            $observer,
            new AiEgressPolicy(allowedHosts: ['localhost'], onSensitiveData: DlpAction::Block),
        );

        try {
            $guard->chat([ChatMessage::user('Summarise the ward rota.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('not permitted', $refusal->getMessage());
            self::assertStringContainsString('api.anthropic.com', $refusal->getMessage());
        }

        self::assertTrue($recorder->sentNothing());
        self::assertSame(AiEgressOutcome::Refused, $observer->only()?->outcome);
    }

    #[Test]
    public function theOnPremisesDestinationTheOperatorPermittedIsReached(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // The other half of the same policy: what is allowed still works.
        $ollama = new OllamaProvider(
            model: 'llama3.1',
            baseUrl: 'http://localhost:11434',
            allowLocalhost: true,
            httpClient: new RecordingHttpClient($recorder, '{"message":{"content":"ok"},"done":true}'),
        );

        $guard = new GuardedAiClient(
            inner: $ollama,
            destination: AiDestination::fromBaseUrl('ollama', 'http://localhost:11434'),
            policy: new AiEgressPolicy(allowedHosts: ['localhost'], onSensitiveData: DlpAction::Block),
            classifier: new SensitivePatternRegistry(new DlpConfig(enabled: true)),
            observer: $observer,
        );

        $guard->chat([ChatMessage::user('Summarise the ward rota.')]);

        self::assertSame(1, $recorder->requestCount());
        self::assertTrue($observer->sawNothing());
    }

    #[Test]
    public function theDefaultPolicyPermitsNoDestinationAtAll(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // A config that forgot the key must not read as "everything allowed".
        $guard = $this->guardOverHttp($recorder, $observer, new AiEgressPolicy());

        $this->expectException(AiEgressRefusedException::class);

        $guard->chat([ChatMessage::user('Anything at all.')]);
    }

    #[Test]
    public function aClientThatDoesNotAnswerToTheDeclaredProviderNameIsRefused(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // The guard is told it wraps Ollama on localhost; it actually wraps the
        // Anthropic client. Nothing above the transport can read a provider's
        // private $baseUrl, so this disagreement between two independent
        // statements is the strongest signal available that the destination
        // declaration does not describe the object below.
        $guard = new GuardedAiClient(
            inner: new AnthropicProvider(
                apiKey: 'test-key',
                httpClient: new RecordingHttpClient($recorder, '{}'),
            ),
            destination: AiDestination::fromBaseUrl('ollama', 'http://localhost:11434'),
            policy: new AiEgressPolicy(allowedHosts: ['localhost']),
            classifier: new SensitivePatternRegistry(new DlpConfig(enabled: true)),
            observer: $observer,
        );

        try {
            $guard->chat([ChatMessage::user('Anything at all.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('reporting "anthropic"', $refusal->getMessage());
        }

        self::assertTrue($recorder->sentNothing());
    }

    // --- Requirement: the same holds on the streaming path -------------------

    #[Test]
    public function theStreamingPathRefusesClassifiedDataWithoutOpeningATransport(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();
        $transport = new RecordingStreamTransport($recorder, self::anthropicFrames());

        $guard = $this->guardOverStream($recorder, $observer, $this->blockingPolicy(), $transport);

        $this->expectException(AiEgressRefusedException::class);

        try {
            $guard->streamChat([ChatMessage::user('Patient SSN is ' . self::SSN)]);
        } finally {
            // The refusal has to land on the call, not on first iteration: the
            // provider builds the request eagerly and a stream that was merely
            // never read would still have been constructed and held.
            self::assertSame(0, $transport->callCount, 'a stream transport was constructed');
            self::assertTrue($recorder->sentNothing());
        }
    }

    #[Test]
    public function theStreamingPathRedactsTheBodyItPutsOnTheWire(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();
        $transport = new RecordingStreamTransport($recorder, self::anthropicFrames());

        $stream = $this->guardOverStream($recorder, $observer, $this->redactingPolicy(), $transport)
            ->streamChat([ChatMessage::user('Patient SSN is ' . self::SSN)]);

        // postStream() runs on first advance, so the body reaches the recorder
        // only once the stream is read. Reading it is the point; the response
        // itself is asserted on elsewhere.
        (void) $stream->response();

        self::assertSame(1, $transport->callCount);
        self::assertFalse(
            $recorder->anyBodyContains(self::SSN),
            'the unredacted value reached the streaming wire',
        );
        self::assertStringContainsString(self::MASKED_SSN, $recorder->onlyBody());
        self::assertSame([AiEgressOutcome::Redacted], $observer->outcomes());
        self::assertSame('stream_chat', $observer->only()?->operation);
    }

    #[Test]
    public function theStreamingPathRefusesAForbiddenDestinationBeforeAnyTransportIsConstructed(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();
        $transport = new RecordingStreamTransport($recorder, self::anthropicFrames());

        $guard = $this->guardOverStream(
            $recorder,
            $observer,
            new AiEgressPolicy(allowedHosts: ['localhost']),
            $transport,
        );

        try {
            $guard->streamChat([ChatMessage::user('Summarise the ward rota.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('not permitted', $refusal->getMessage());
        }

        self::assertSame(0, $transport->callCount);
        self::assertTrue($recorder->sentNothing());
    }

    #[Test]
    public function theStreamingPathRefusesWhenTheClassifierIsSwitchedOff(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();
        $transport = new RecordingStreamTransport($recorder, self::anthropicFrames());

        $guard = $this->guardOverStream(
            $recorder,
            $observer,
            $this->blockingPolicy(),
            $transport,
            new SensitivePatternRegistry(new DlpConfig(enabled: false)),
        );

        try {
            $guard->streamChat([ChatMessage::user('Perfectly ordinary text.')]);
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('did not examine the payload', $refusal->getMessage());
        }

        self::assertSame(0, $transport->callCount);
        self::assertTrue($recorder->sentNothing());
    }

    #[Test]
    public function aCleanStreamedPayloadStillReachesTheTransport(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();
        $transport = new RecordingStreamTransport($recorder, self::anthropicFrames());

        $response = $this->guardOverStream($recorder, $observer, $this->blockingPolicy(), $transport)
            ->streamChat([ChatMessage::user('Summarise the ward rota.')])
            ->response();

        // The guard must not break the contract it wraps: the accumulated
        // response still carries the provider's own token counts.
        self::assertSame('Hello, world', $response->content);
        self::assertSame(12, $response->inputTokens);
        self::assertSame(7, $response->outputTokens);
        self::assertSame(1, $transport->callCount);
        self::assertTrue($observer->sawNothing());
    }

    // --- The other methods that send bytes -----------------------------------

    #[Test]
    public function embeddedDocumentsAreClassifiedBeforeTheyAreEmbedded(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $guard = $this->guardOverHttp(
            $recorder,
            $observer,
            $this->blockingPolicy(),
            null,
            new OllamaProvider(
                model: 'nomic-embed-text',
                baseUrl: 'http://localhost:11434',
                allowLocalhost: true,
                httpClient: new RecordingHttpClient($recorder, '{"embeddings":[[0.1]]}'),
            ),
            'ollama',
            'http://localhost:11434',
            ['localhost'],
        );

        $this->expectException(AiEgressRefusedException::class);

        try {
            // A RAG pipeline hands this method the documents themselves, which
            // makes it the largest volume of personal data the module can emit.
            $guard->embed(['Discharge summary for patient SSN ' . self::SSN]);
        } finally {
            self::assertTrue($recorder->sentNothing());
        }
    }

    #[Test]
    public function aSinglePromptCompletionIsClassified(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        $guard = $this->guardOverHttp($recorder, $observer, $this->blockingPolicy());

        $this->expectException(AiEgressRefusedException::class);

        try {
            $guard->complete('Summarise the record for SSN ' . self::SSN);
        } finally {
            self::assertTrue($recorder->sentNothing());
        }
    }

    #[Test]
    public function classifiedDataInAToolDefinitionIsRefusedRatherThanMaskedIntoNonsense(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // Under Redact the free text would be masked. A tool definition cannot
        // be: masking a span inside the JSON document produces a payload that is
        // no longer the document, sent quietly — the exact "silently mangled"
        // failure. So it is refused instead.
        $guard = $this->guardOverHttp($recorder, $observer, $this->redactingPolicy());

        try {
            $guard->chat(
                [ChatMessage::user('Look it up.')],
                new AiRequestOptions(tools: [
                    new ToolDefinition('lookup', 'Finds the record for SSN ' . self::SSN, []),
                ]),
            );
            self::fail('the call was not refused');
        } catch (AiEgressRefusedException $refusal) {
            self::assertStringContainsString('structured field', $refusal->getMessage());
        }

        self::assertTrue($recorder->sentNothing());
    }

    #[Test]
    public function alertPolicySendsThePayloadAndSaysSo(): void
    {
        $recorder = new WireRecorder();
        $observer = new RecordingEgressObserver();

        // A legitimate operator choice, and the one case where classified data
        // does leave. It must never be silent about it.
        $guard = $this->guardOverHttp(
            $recorder,
            $observer,
            new AiEgressPolicy(allowedHosts: ['api.anthropic.com'], onSensitiveData: DlpAction::Alert),
        );

        $guard->chat([ChatMessage::user('Patient SSN is ' . self::SSN)]);

        self::assertTrue($recorder->anyBodyContains(self::SSN), 'alert-only should send the payload');
        self::assertSame([AiEgressOutcome::Allowed], $observer->outcomes());
        self::assertSame([SensitiveDataType::Ssn], $observer->only()?->sensitiveDataTypes());
    }

    // --- Builders ------------------------------------------------------------

    /**
     * @param list<string> $allowedHosts
     */
    private function guardOverHttp(
        WireRecorder $recorder,
        RecordingEgressObserver $observer,
        AiEgressPolicy $policy,
        ?SensitivePatternRegistry $classifier = null,
        ?AiClientInterface $inner = null,
        string $providerName = 'anthropic',
        string $baseUrl = 'https://api.anthropic.com/v1',
        array $allowedHosts = [],
    ): GuardedAiClient {
        return new GuardedAiClient(
            inner: $inner ?? new AnthropicProvider(
                apiKey: 'test-key',
                model: 'claude-sonnet-4-6',
                baseUrl: $baseUrl,
                httpClient: new RecordingHttpClient($recorder, self::ANTHROPIC_RESPONSE),
            ),
            destination: AiDestination::fromBaseUrl($providerName, $baseUrl),
            policy: $allowedHosts === []
                ? $policy
                : new AiEgressPolicy(allowedHosts: $allowedHosts, onSensitiveData: $policy->onSensitiveData),
            classifier: $classifier ?? new SensitivePatternRegistry(new DlpConfig(enabled: true)),
            observer: $observer,
        );
    }

    private function guardOverStream(
        WireRecorder $recorder,
        RecordingEgressObserver $observer,
        AiEgressPolicy $policy,
        RecordingStreamTransport $transport,
        ?SensitivePatternRegistry $classifier = null,
    ): GuardedAiClient {
        return new GuardedAiClient(
            inner: new AnthropicProvider(
                apiKey: 'test-key',
                model: 'claude-sonnet-4-6',
                baseUrl: 'https://api.anthropic.com/v1',
                streamTransport: $transport,
            ),
            destination: AiDestination::fromBaseUrl('anthropic', 'https://api.anthropic.com/v1'),
            policy: $policy,
            classifier: $classifier ?? new SensitivePatternRegistry(new DlpConfig(enabled: true)),
            observer: $observer,
        );
    }

    private function blockingPolicy(): AiEgressPolicy
    {
        return new AiEgressPolicy(allowedHosts: ['api.anthropic.com'], onSensitiveData: DlpAction::Block);
    }

    private function redactingPolicy(): AiEgressPolicy
    {
        return new AiEgressPolicy(allowedHosts: ['api.anthropic.com'], onSensitiveData: DlpAction::Redact);
    }

    private const string ANTHROPIC_RESPONSE = '{"id":"msg_1","model":"claude-sonnet-4-6",'
        . '"content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn",'
        . '"usage":{"input_tokens":1,"output_tokens":1}}';

    /**
     * A complete Anthropic SSE exchange, so a permitted stream really finishes.
     *
     * @return list<string>
     */
    private static function anthropicFrames(): array
    {
        return [
            "event: message_start\n"
                . 'data: {"type":"message_start","message":{"id":"msg_1","model":"claude-sonnet-4-6",'
                . '"usage":{"input_tokens":12,"output_tokens":1}}}' . "\n\n",
            "event: content_block_start\n"
                . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}'
                . "\n\n",
            "event: content_block_delta\n"
                . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}'
                . "\n\n",
            "event: content_block_delta\n"
                . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":", world"}}'
                . "\n\n",
            "event: content_block_stop\n"
                . 'data: {"type":"content_block_stop","index":0}' . "\n\n",
            "event: message_delta\n"
                . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":7}}'
                . "\n\n",
            "event: message_stop\n"
                . 'data: {"type":"message_stop"}' . "\n\n",
        ];
    }
}
