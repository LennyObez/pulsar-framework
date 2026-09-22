<?php

declare(strict_types=1);

namespace Pulsar\AI\Audit;

use Generator;
use InvalidArgumentException;
use Override;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\AI\Streaming\AiStreamDelta;
use Pulsar\AI\Streaming\AiStreamEventType;
use Pulsar\AI\Streaming\AiTokenUsage;
use Pulsar\AI\ToolCall;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\Hmac;
use SensitiveParameter;
use SodiumException;
use Throwable;

use function array_map;
use function array_values;
use function count;
use function hrtime;
use function implode;
use function intdiv;
use function is_string;
use function json_encode;
use function ksort;
use function sprintf;
use function strlen;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN;

/**
 * Writes one tamper-evident audit entry for every inference the core AI layer
 * performs, and refuses to lose one.
 *
 * WHY THIS IS IN THE CORE. An `AiAuditLoggerInterface` has shipped in the
 * optional `ai-governance` extension since 1.0.0, which is off by default and
 * absent from the default enabled-products list. A deployment that wired an AI
 * client and did not install that extension could call a language model, act on
 * the answer, and leave no record anywhere. Under SOC 2 CC7, ISO/IEC 42001
 * clause 9.1 and the EU AI Act's logging obligations for high-risk systems, an
 * inference that informs a regulated decision needs a trail, and "install the
 * optional extension" is not a default posture a bank can adopt.
 *
 * WHAT IS RECORDED, and this is the whole design:
 *
 *   - WHO and WHEN. {@see \Pulsar\Security\Audit\AuditEntry} carries the
 *     timestamp, and the logger stamps the correlation and causation ids of the
 *     live request. The actor is the AI seam itself (`system:ai.inference`)
 *     rather than a user id this decorator would have to invent: this class
 *     measures the call, not the identity behind it, and the human is reached by
 *     joining on the correlation id to the authentication entry that does
 *     measure it (ADR-0050).
 *   - WHAT MODEL. Provider, the model the caller ASKED for, and the model the
 *     provider SAID it used. Two fields because they disagree in practice — an
 *     alias resolving to a dated snapshot, a gateway silently failing over — and
 *     an assessor asking "which model decided this" needs the second one.
 *   - WHAT IT COST. Input, output and total tokens, taken from the provider's own
 *     accounting.
 *   - HOW IT ENDED. Finish reason, latency in microseconds measured across the
 *     call by this decorator, and for a stream whether it completed.
 *   - WHAT THE EGRESS CONTROL DECIDED. Copied from {@see EgressDecision}, never
 *     inferred — see {@see EgressDecisionSourceInterface}. Three states, kept
 *     apart on purpose: `not_observed` when no control is composed at all,
 *     `no_finding` when one is composed and reported nothing, and the control's
 *     own outcome otherwise. Neither of the first two is ever written as "clean".
 *   - WHAT TOOLS THE MODEL ASKED FOR. Count and names. A model that reached for
 *     `transfer_funds` is the single most consequential fact a trail can hold.
 *
 * WHAT IS DELIBERATELY NOT RECORDED: the prompt, the completion, the tool call
 * ARGUMENTS, the JSON schema, and the embedding inputs — none of them in the
 * clear, ever. An audit file has a longer retention than anything else in the
 * estate, and writing into it the personal data the egress seam had just refused
 * to send would be a second leak into a more durable place. Nor are provider
 * exception MESSAGES recorded: providers routinely echo the offending request
 * back in an error, so only the exception's class name is kept.
 *
 * WHAT STANDS IN FOR THE CONTENT is a keyed BLAKE2b digest of a canonical,
 * length-prefixed rendering, plus a character count. Keyed, not a bare hash: a
 * prompt is usually low-entropy — a name, an account number, one of a few
 * hundred templates — and a plain SHA-256 of it is recovered by guessing. The key
 * is a KDF sub-key held by the running application, so possession of the audit
 * file alone reveals nothing, while an investigator holding both the file and a
 * candidate prompt can prove the match, and anyone holding the file can prove two
 * entries carried the SAME prompt without learning what it was.
 *
 * STREAMING is recorded at the end, because that is when the facts exist. Token
 * counts are not final until the terminal event, so a record written when
 * `streamChat()` returned would say nothing about cost. Every way a stream can
 * end writes exactly one entry: read to completion, it is recorded complete;
 * broken or refused mid-flight, it is recorded incomplete with whatever partial
 * accounting arrived; abandoned by a consumer that stopped iterating and dropped
 * it, the generator's `finally` still runs and it is recorded incomplete. A
 * stream that dies is the case that most needs a trail and is the easiest to
 * lose.
 *
 * COMPOSITION. This decorator must be the OUTERMOST wrapper on
 * {@see AiClientInterface}, outside any egress control, so that it observes what
 * the control decided and records a refusal as a refusal.
 * {@see \Pulsar\Core\Wiring\AiAuditWiring} composes it that way.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditingAiClient implements AiClientInterface
{
    /**
     * Action recorded on every inference entry, so one filter finds them all.
     *
     * The operation is a metadata field rather than part of this string:
     * `action = 'ai.inference'` keeps the trail queryable for "every model call"
     * without an assessor having to know the five spellings.
     */
    public const string ACTION = 'ai.inference';

    /** Component name behind the `system:` actor on every inference entry. */
    public const string ACTOR_COMPONENT = 'ai.inference';

    /**
     * Value of `ai_egress` when this decorator has no egress channel at all.
     *
     * Nothing looked. Distinct from {@see EGRESS_NO_FINDING}, and the distinction
     * is the point: a deployment with no egress control must not read in the
     * trail like one whose control examined the payload and passed it.
     */
    public const string EGRESS_NOT_OBSERVED = 'not_observed';

    /**
     * Value of `ai_egress` when a channel is composed and reported nothing.
     *
     * {@see EgressDecisionSinkInterface} states the invariant this rests on: a
     * control that reports nothing for a call found nothing classified on it.
     */
    public const string EGRESS_NO_FINDING = 'no_finding';

    /** Value of `ai_egress` when the control inspected the payload and forwarded it. */
    public const string EGRESS_PASSED = 'passed';

    /** Value of `ai_egress` when the control rewrote part of the payload. */
    public const string EGRESS_REDACTED = 'redacted';

    /** Value of `ai_egress` when the control stopped the call. */
    public const string EGRESS_REFUSED = 'refused';

    /**
     * @param AiClientInterface $inner The client whose inferences are recorded
     * @param AuditLoggerInterface $auditLogger The HMAC-chained trail; never a null
     *        logger — see {@see \Pulsar\Core\Wiring\AiAuditWiring}
     * @param string $contentDigestKey KDF sub-key for the content digests, at least 16 bytes
     * @param EgressDecisionSourceInterface|null $egress Where an egress control's
     *        decision is taken from, when one is composed
     *
     * @throws InvalidArgumentException When the digest key is too short for keyed
     *         BLAKE2b, which would otherwise surface as a failure on the first
     *         inference rather than at composition time.
     */
    public function __construct(
        private AiClientInterface $inner,
        private AuditLoggerInterface $auditLogger,
        #[SensitiveParameter]
        private string $contentDigestKey,
        private ?EgressDecisionSourceInterface $egress = null,
    ) {
        if (strlen($contentDigestKey) < SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN) {
            throw new InvalidArgumentException(sprintf(
                'AuditingAiClient needs a content digest key of at least %d bytes, got %d',
                SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN,
                strlen($contentDigestKey),
            ));
        }
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @throws Throwable Whatever the inner client raised, after it has been recorded
     */
    #[Override]
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        return $this->audited(
            InferenceOperation::Chat,
            $this->conversationFacts($messages, $options),
            fn(): AiResponse => $this->inner->chat($messages, $options),
            fn(AiResponse $response): array => $this->responseFacts($response),
        );
    }

    /**
     * @throws Throwable Whatever the inner client raised, after it has been recorded
     */
    #[Override]
    public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        return $this->audited(
            InferenceOperation::Complete,
            $this->promptFacts([$prompt], $options),
            fn(): AiResponse => $this->inner->complete($prompt, $options),
            fn(AiResponse $response): array => $this->responseFacts($response),
        );
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @throws Throwable Whatever the inner client raised, after it has been recorded
     */
    #[Override]
    public function structuredOutput(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): AiResponse {
        $facts = $this->promptFacts([$prompt], $options);
        // The schema is digested rather than listed. Its property names describe
        // the shape an application asked for, which is often as revealing as a
        // value ("hiv_status", "sanctions_hit"), and the digest still answers the
        // question an assessor asks: were these answers all constrained by the
        // same schema, or did it change under them?
        $facts['ai_schema_digest'] = $this->digest([$this->encodeSchema($schema)]);

        return $this->audited(
            InferenceOperation::StructuredOutput,
            $facts,
            fn(): AiResponse => $this->inner->structuredOutput($prompt, $schema, $options),
            fn(AiResponse $response): array => $this->responseFacts($response),
        );
    }

    /**
     * @param list<string> $inputs
     *
     * @throws Throwable Whatever the inner client raised, after it has been recorded
     */
    #[Override]
    public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): EmbeddingResult
    {
        return $this->audited(
            InferenceOperation::Embed,
            $this->promptFacts($inputs, $options),
            fn(): EmbeddingResult => $this->inner->embed($inputs, $options),
            static fn(EmbeddingResult $result): array => [
                'ai_model_reported' => $result->model,
                'ai_total_tokens' => $result->totalTokens,
                'ai_embedding_count' => $result->count(),
            ],
        );
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @throws Throwable Whatever the inner client raised, after it has been recorded
     */
    #[Override]
    public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream
    {
        $requestFacts = $this->conversationFacts($messages, $options);
        $startedNs = hrtime(true);

        try {
            $inner = $this->inner->streamChat($messages, $options);
        } catch (Throwable $failure) {
            // The stream never opened. An egress control that refuses before
            // dialling out fails here, and this is the record of that refusal.
            $this->record(
                InferenceOperation::StreamChat,
                $requestFacts,
                ['ai_stream_complete' => false, 'ai_stream_abandoned' => false, 'ai_stream_deltas' => 0],
                $startedNs,
                $failure,
                $this->takeDecision(),
            );

            throw $failure;
        }

        // Taken NOW, not when the record is written. An egress control decides
        // about a streamed call when the call is made — the request is what it
        // inspects — while this record is written when the stream ENDS, which may
        // be after other calls have gone through the same one-slot channel. A
        // caller holding two streams open at once would otherwise have the
        // second call's decision filed against the first.
        $decision = $this->takeDecision();

        return new AiStream(
            $this->recordingDeltas($inner, $requestFacts, $startedNs, $decision),
            $inner->providerName,
        );
    }

    #[Override]
    public function providerName(): string
    {
        return $this->inner->providerName();
    }

    /**
     * Re-yield the inner stream's deltas, then record exactly one entry for
     * however the stream ended.
     *
     * The `finally` is load-bearing. PHP runs it when a suspended generator is
     * destroyed, which is what a consumer that stops iterating and drops the
     * stream looks like — the one ending that produces neither a response nor an
     * exception, and the one an audit trail would otherwise silently lose.
     *
     * @param array<string, mixed> $requestFacts
     * @param EgressDecision|null $decision The decision the control made about THIS
     *        call, taken when the call was made rather than when the stream ends
     *
     * @return Generator<int, AiStreamDelta, mixed, AiResponse>
     *
     * @throws Throwable
     */
    private function recordingDeltas(
        AiStream $inner,
        array $requestFacts,
        int $startedNs,
        ?EgressDecision $decision,
    ): Generator {
        $deltas = 0;
        $textChars = 0;
        /** @var array<int, string> $toolNames */
        $toolNames = [];
        $inputTokens = null;
        $outputTokens = null;
        $finishReason = null;
        $recorded = false;

        try {
            foreach ($inner as $delta) {
                ++$deltas;

                switch ($delta->type) {
                    case AiStreamEventType::Text:
                        $textChars += strlen($delta->text);

                        break;
                    case AiStreamEventType::ToolCall:
                        $toolNames = $this->withToolName($toolNames, $delta);

                        break;
                    case AiStreamEventType::Usage:
                        [$inputTokens, $outputTokens] = $this->foldUsage($delta->usage, $inputTokens, $outputTokens);

                        break;
                    case AiStreamEventType::Finish:
                        $finishReason = $delta->finishReason;

                        break;
                }

                yield $delta;
            }

            $response = $inner->response();

            $this->record(
                InferenceOperation::StreamChat,
                $requestFacts,
                [
                    ...$this->responseFacts($response),
                    'ai_stream_complete' => true,
                    'ai_stream_abandoned' => false,
                    'ai_stream_deltas' => $deltas,
                ],
                $startedNs,
                null,
                $decision,
            );
            $recorded = true;

            return $response;
        } catch (Throwable $failure) {
            $this->record(
                InferenceOperation::StreamChat,
                $requestFacts,
                $this->partialStreamFacts($deltas, $textChars, $toolNames, $inputTokens, $outputTokens, $finishReason, false),
                $startedNs,
                $failure,
                $decision,
            );
            $recorded = true;

            throw $failure;
        } finally {
            if (!$recorded) {
                $this->record(
                    InferenceOperation::StreamChat,
                    $requestFacts,
                    $this->partialStreamFacts($deltas, $textChars, $toolNames, $inputTokens, $outputTokens, $finishReason, true),
                    $startedNs,
                    null,
                    $decision,
                );
            }
        }
    }

    /**
     * Facts about a stream that did not reach its terminal event.
     *
     * `ai_stream_complete` is false because no {@see AiResponse} was produced —
     * observed, not assumed. The token counts are whatever the provider had
     * reported by then, and are omitted rather than zeroed when it reported none:
     * a literal 0 would be this class asserting a measurement it never made.
     *
     * @param array<int, string> $toolNames
     *
     * @return array<string, mixed>
     */
    private function partialStreamFacts(
        int $deltas,
        int $textChars,
        array $toolNames,
        ?int $inputTokens,
        ?int $outputTokens,
        ?string $finishReason,
        bool $abandoned,
    ): array {
        $facts = [
            'ai_stream_complete' => false,
            'ai_stream_abandoned' => $abandoned,
            'ai_stream_deltas' => $deltas,
            'ai_completion_chars' => $textChars,
            'ai_tool_call_count' => count($toolNames),
            'ai_tool_names' => $this->orderedToolNames($toolNames),
        ];

        if ($inputTokens !== null) {
            $facts['ai_input_tokens'] = $inputTokens;
        }

        if ($outputTokens !== null) {
            $facts['ai_output_tokens'] = $outputTokens;
        }

        if ($finishReason !== null) {
            $facts['ai_finish_reason'] = $finishReason;
        }

        return $facts;
    }

    /**
     * Fold one usage event into the running counts.
     *
     * Last report wins PER FIELD, which is the rule
     * {@see \Pulsar\AI\Streaming\AiStreamAccumulator} also follows and the only
     * one that keeps both halves: Anthropic opens with the input count and closes
     * with the output count, so overwriting a number an event did not carry would
     * throw one of the two away. A null therefore leaves the previous value
     * standing rather than becoming zero.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function foldUsage(?AiTokenUsage $usage, ?int $inputTokens, ?int $outputTokens): array
    {
        if ($usage === null) {
            return [$inputTokens, $outputTokens];
        }

        return [$usage->inputTokens ?? $inputTokens, $usage->outputTokens ?? $outputTokens];
    }

    /**
     * @param array<int, string> $toolNames
     *
     * @return array<int, string>
     */
    private function withToolName(array $toolNames, AiStreamDelta $delta): array
    {
        $fragment = $delta->toolCall;

        if ($fragment === null || $fragment->name === null || $fragment->name === '') {
            return $toolNames;
        }

        $toolNames[$fragment->index] = $fragment->name;

        return $toolNames;
    }

    /**
     * @param array<int, string> $toolNames
     *
     * @return list<string>
     */
    private function orderedToolNames(array $toolNames): array
    {
        ksort($toolNames);

        return array_values($toolNames);
    }

    /**
     * Run one inference, record it, and let anything it raised continue.
     *
     * Recording happens on both paths and before the rethrow, so a failed call is
     * as auditable as a successful one — a refusal in particular, which is the
     * event an operator most wants and the one a naive decorator loses by
     * recording only after a successful return.
     *
     * @template TResult
     *
     * @param array<string, mixed> $requestFacts
     * @param callable(): TResult $call
     * @param callable(TResult): array<string, mixed> $resultFacts
     *
     * @return TResult
     *
     * @throws Throwable
     */
    private function audited(
        InferenceOperation $operation,
        array $requestFacts,
        callable $call,
        callable $resultFacts,
    ): mixed {
        $startedNs = hrtime(true);

        try {
            /** @var TResult $result */
            $result = $call();
        } catch (Throwable $failure) {
            // Taken here, immediately, and on both paths. An egress control
            // reports before it raises, so the decision belonging to THIS call is
            // in the slot exactly now — and taking it now is also what stops it
            // being attributed to the next one.
            $this->record($operation, $requestFacts, [], $startedNs, $failure, $this->takeDecision());

            throw $failure;
        }

        $this->record($operation, $requestFacts, $resultFacts($result), $startedNs, null, $this->takeDecision());

        return $result;
    }

    /**
     * The egress decision for the call that just finished, if a channel is
     * composed and reported one.
     */
    private function takeDecision(): ?EgressDecision
    {
        return $this->egress?->takeEgressDecision();
    }

    /**
     * Write the entry.
     *
     * @param array<string, mixed> $requestFacts
     * @param array<string, mixed> $resultFacts
     *
     * @throws Throwable When the audit chain itself cannot be advanced, which is
     *         not a condition to continue from: an inference whose record could
     *         not be written is an unauditable inference.
     */
    private function record(
        InferenceOperation $operation,
        array $requestFacts,
        array $resultFacts,
        int $startedNs,
        ?Throwable $failure,
        ?EgressDecision $decision,
    ): void {
        $provider = $this->inner->providerName();

        $metadata = [
            'ai_operation' => $operation->value,
            'ai_provider' => $provider,
            // Measured here, across the whole call, by the only object that spans
            // it. Monotonic: a wall clock stepped by NTP mid-inference would
            // produce a negative duration.
            'ai_latency_us' => intdiv(hrtime(true) - $startedNs, 1000),
            ...$requestFacts,
            ...$resultFacts,
            ...$this->egressFacts($decision),
        ];

        if ($failure !== null) {
            // The CLASS only. Provider error messages quote the request back, so
            // `getMessage()` here would put the prompt into the audit file that
            // exists partly to prove the prompt was never stored.
            $metadata['ai_error'] = $failure::class;
        }

        $this->auditLogger->log(
            event: AuditEvent::Communication,
            outcome: $this->outcomeFor($failure, $decision),
            actor: AuditActor::system(self::ACTOR_COMPONENT),
            action: self::ACTION,
            resource: $this->resourceFor($provider, $metadata),
            metadata: $metadata,
        );
    }

    /**
     * The subject of the record: the model, when either side named one.
     *
     * @param array<string, mixed> $metadata
     */
    private function resourceFor(string $provider, array $metadata): string
    {
        foreach (['ai_model_reported', 'ai_model_requested'] as $key) {
            /** @var mixed $model */
            $model = $metadata[$key] ?? null;

            if (is_string($model) && $model !== '') {
                return $provider . ':' . $model;
            }
        }

        return $provider;
    }

    /**
     * A refusal outranks the exception that carried it: the egress control
     * MEASURED a denial, whereas the exception class is only how it travelled.
     * Nothing here guesses — with no decision reported, a failure is an error.
     */
    private function outcomeFor(?Throwable $failure, ?EgressDecision $decision): AuditOutcome
    {
        if ($decision !== null && $decision->refused) {
            return AuditOutcome::Denied;
        }

        return $failure === null ? AuditOutcome::Success : AuditOutcome::Error;
    }

    /**
     * Three states, and none of them is guessed.
     *
     * With no channel composed, nothing looked, and the record says so. With a
     * channel composed and nothing reported, the control was consulted and found
     * nothing — the invariant {@see EgressDecisionSinkInterface} states. With a
     * decision in hand, the outcome is DERIVED from the fields the control set,
     * never copied from a status this class chose.
     *
     * @return array<string, mixed>
     */
    private function egressFacts(?EgressDecision $decision): array
    {
        if ($this->egress === null) {
            return ['ai_egress' => self::EGRESS_NOT_OBSERVED];
        }

        if ($decision === null) {
            return ['ai_egress' => self::EGRESS_NO_FINDING];
        }

        $facts = [
            'ai_egress' => match (true) {
                $decision->refused => self::EGRESS_REFUSED,
                $decision->redactionApplied => self::EGRESS_REDACTED,
                default => self::EGRESS_PASSED,
            },
            'ai_redaction_applied' => $decision->redactionApplied,
            'ai_redaction_spans' => $decision->redactedSpanCount,
            'ai_redaction_categories' => $decision->categories,
            'ai_egress_refused' => $decision->refused,
        ];

        if ($decision->refused) {
            $facts['ai_refusal_reason'] = $decision->refusalReason;
        }

        return $facts;
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @return array<string, mixed>
     *
     * @throws SodiumException
     */
    private function conversationFacts(array $messages, AiRequestOptions $options): array
    {
        $parts = [];
        $roles = [];
        $chars = 0;

        foreach ($messages as $message) {
            $role = $message->role->value;
            $roles[$role] = ($roles[$role] ?? 0) + 1;
            $chars += strlen($message->content);
            $parts[] = $role;
            $parts[] = $message->content;
        }

        return [
            ...$this->optionFacts($options),
            'ai_message_count' => count($messages),
            'ai_roles' => $roles,
            'ai_prompt_chars' => $chars,
            'ai_prompt_digest' => $this->digest($parts),
        ];
    }

    /**
     * @param list<string> $texts
     *
     * @return array<string, mixed>
     *
     * @throws SodiumException
     */
    private function promptFacts(array $texts, AiRequestOptions $options): array
    {
        $chars = 0;

        foreach ($texts as $text) {
            $chars += strlen($text);
        }

        return [
            ...$this->optionFacts($options),
            'ai_input_count' => count($texts),
            'ai_prompt_chars' => $chars,
            'ai_prompt_digest' => $this->digest($texts),
        ];
    }

    /**
     * Facts the caller asked for, as opposed to facts the provider reported.
     *
     * The system prompt is digested separately from the conversation because it
     * is the instruction that shaped the answer and it changes on a different
     * schedule from the messages — an assessor asking "was this decision made
     * under the reviewed system prompt" gets a straight answer.
     *
     * @return array<string, mixed>
     *
     * @throws SodiumException
     */
    private function optionFacts(AiRequestOptions $options): array
    {
        $facts = [
            'ai_model_requested' => $options->model ?? '',
            'ai_tools_offered' => count($options->tools),
            'ai_timeout_seconds' => $options->timeoutSeconds,
        ];

        if ($options->temperature !== null) {
            $facts['ai_temperature'] = $options->temperature;
        }

        if ($options->maxTokens !== null) {
            $facts['ai_max_tokens'] = $options->maxTokens;
        }

        if ($options->responseFormat !== null) {
            $facts['ai_response_format'] = $options->responseFormat;
        }

        if ($options->systemPrompt !== null) {
            $facts['ai_system_prompt_chars'] = strlen($options->systemPrompt);
            $facts['ai_system_prompt_digest'] = $this->digest([$options->systemPrompt]);
        }

        return $facts;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SodiumException
     */
    private function responseFacts(AiResponse $response): array
    {
        return [
            'ai_model_reported' => $response->model,
            'ai_input_tokens' => $response->inputTokens,
            'ai_output_tokens' => $response->outputTokens,
            'ai_total_tokens' => $response->totalTokens(),
            'ai_finish_reason' => $response->finishReason,
            'ai_completion_chars' => strlen($response->content),
            'ai_completion_digest' => $this->digest([$response->content]),
            'ai_tool_call_count' => count($response->toolCalls),
            // Names, never arguments: the arguments are the model's copy of
            // whatever the prompt contained.
            'ai_tool_names' => array_map(static fn(ToolCall $call): string => $call->name, $response->toolCalls),
        ];
    }

    /**
     * A keyed digest of a canonical, length-prefixed rendering of the parts.
     *
     * Length-prefixed for the same reason {@see \Pulsar\Security\Audit\AuditEntry}
     * is: without it, two different conversations concatenate to the same bytes
     * and collide, and a colliding digest is a false match in an investigation.
     *
     * @param list<string> $parts
     *
     * @throws SodiumException
     */
    private function digest(array $parts): string
    {
        $encoded = [];

        foreach ($parts as $part) {
            $encoded[] = strlen($part) . ':' . $part;
        }

        return Hmac::computeHex(implode("\n", $encoded), $this->contentDigestKey);
    }

    /**
     * The schema, rendered deterministically for digesting.
     *
     * A schema that will not encode is still a fact worth digesting consistently,
     * so an unencodable one digests its own shape rather than aborting the record.
     *
     * @param array<string, mixed> $schema
     */
    private function encodeSchema(array $schema): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? 'unencodable-schema:' . count($schema) : $json;
    }
}
