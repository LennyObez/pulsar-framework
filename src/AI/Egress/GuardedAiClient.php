<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use Override;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Audit\EgressDecisionSinkInterface;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Exception\AiEgressRefusedException;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\Api\Api;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpMatch;
use Pulsar\Security\Dlp\DlpScanResult;
use Pulsar\Security\Dlp\SensitiveDataClassifierInterface;
use Throwable;

use function array_key_last;
use function array_slice;
use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The seam every outbound AI payload runs into before a provider can transport
 * it.
 *
 * `src/AI/Provider/` ships three clients. Two of them post whatever they are
 * handed to an endpoint outside the deployment, and nothing in `src/AI`
 * inspected, classified or refused that payload — in a framework that ships a
 * sensitive-data classifier, a pseudonymisation service, an Article 17 forget
 * service and a tokenisation vault. This class is the missing half: the
 * deployment's own statement about personal data, applied to the one path that
 * takes personal data out of the deployment.
 *
 * WHAT IT DOES, in order, on every method that sends bytes:
 *
 * 1. **Coherence.** The wrapped client must answer to the provider name the
 *    destination was declared for. Two independent statements about what sits
 *    below; when they disagree the guard does not know what it is protecting.
 * 2. **Destination.** The host must be on the operator's allow-list. This runs
 *    before any payload work, so a forbidden endpoint is refused without a
 *    request ever being constructed.
 * 3. **Classification.** Every outbound string goes through
 *    {@see SensitivePatternRegistry} — the framework's one classifier of free
 *    text. If the classifier did not examine the bytes, for any reason, the call
 *    is refused.
 * 4. **Policy.** {@see AiEgressPolicy::$onSensitiveData} decides what a detection
 *    means: block, mask, or send and report.
 *
 * IT FAILS CLOSED, and the interesting case is the quiet one. A
 * `SensitivePatternRegistry` built on a `DlpConfig` with `enabled: false` answers
 * every question with "nothing found" without reading a byte, and DLP is not
 * wired by any of Pulsar's fifty-odd wirings — so "no classifier" is the state of
 * a stock deployment, not an edge case. The guard therefore keys on
 * {@see \Pulsar\Security\Dlp\DlpScanStatus::isConclusive()} — did the scanner
 * examine these bytes — and never on `detected === false`, which a disabled
 * registry, an exhausted PCRE engine and a genuinely clean prompt all report
 * identically.
 *
 * COMPOSITION ORDER, agreed with the audit decorator built alongside this one:
 * `AuditingAiClient(GuardedAiClient(Provider))` — audit outermost, guard beneath
 * it, provider innermost. A refused call must still reach the audit sink, because
 * "someone tried to send health data to a hosted endpoint" is the event an
 * assessor asks for first; with the guard outermost the refusal would raise
 * before the audit decorator ever saw the call. The consequence is stated rather
 * than hidden: the audit decorator sees the payload as the caller wrote it, not
 * as it was redacted. That is the right way round — the audit log lives inside
 * the deployment, and this guard is about what leaves it — but it means the audit
 * sink holds unredacted prompts and must be protected accordingly.
 *
 * WHAT IT DOES NOT DO. It reads the request path only. Model output coming back
 * is not examined, and a provider that echoes a prompt is outside this seam.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class GuardedAiClient implements AiClientInterface
{
    /**
     * @param AiClientInterface          $inner       The client that actually transports
     * @param AiDestination              $destination Where that client was configured to send
     * @param AiEgressPolicy             $policy      What the operator permits
     * @param SensitiveDataClassifierInterface $classifier The classifier of free text
     * @param AiEgressObserverInterface  $observer    Told about every rewrite and refusal;
     *                                                required, see the interface
     * @param EgressDecisionSinkInterface|null $auditSink The one-slot channel the
     *        auditing decorator takes its egress decision from, when one is
     *        composed. Optional because it exists only when `AuditingAiClient`
     *        wraps this guard — unlike {@see $observer}, it is a reporting
     *        channel rather than a control, and its absence removes no check
     */
    public function __construct(
        private AiClientInterface $inner,
        private AiDestination $destination,
        private AiEgressPolicy $policy,
        private SensitiveDataClassifierInterface $classifier,
        private AiEgressObserverInterface $observer,
        private ?EgressDecisionSinkInterface $auditSink = null,
    ) {}

    #[Override]
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        [$messages, $options] = $this->guardChat('chat', $messages, $options);

        return $this->inner->chat($messages, $options);
    }

    /**
     * The streamed path runs the same guard as {@see chat()}, before delegating.
     *
     * The whole check is on the request, so there is nothing to observe in the
     * deltas and the inner {@see AiStream} is returned untouched — no wrapping
     * generator, which leaves the stream's single-pass and terminal-event
     * behaviour exactly as the provider built it, and leaves the delta-observing
     * wrapper to the audit decorator that needs one.
     *
     * The refusal happens on the call to this method rather than on first
     * iteration. That matters: the providers validate their URL eagerly for the
     * same reason, and a guard that only fired when someone got round to
     * iterating would let a refused call be constructed, held, and — under the
     * socket-opening transport — connected.
     */
    #[Override]
    public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream
    {
        [$messages, $options] = $this->guardChat('stream_chat', $messages, $options);

        return $this->inner->streamChat($messages, $options);
    }

    #[Override]
    public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse
    {
        $this->assertDestinationPermitted('complete');

        $free = [$prompt];
        $free = $this->appendSystemPrompt($free, $options);

        $redacted = $this->apply('complete', $free, $this->structuredSpansOf('complete', $options, null));

        return $this->inner->complete(
            $redacted[0],
            $this->rebuildOptions($options, $redacted),
        );
    }

    #[Override]
    public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): EmbeddingResult
    {
        $this->assertDestinationPermitted('embed');

        // Every input is free text and every input leaves the deployment. This is
        // the method a RAG pipeline calls with the documents themselves, so it is
        // the largest single volume of personal data the AI module can emit.
        $free = $inputs;
        $free = $this->appendSystemPrompt($free, $options);

        $redacted = $this->apply('embed', $free, $this->structuredSpansOf('embed', $options, null));

        return $this->inner->embed(
            array_slice($redacted, 0, count($inputs)),
            $this->rebuildOptions($options, $redacted),
        );
    }

    #[Override]
    public function structuredOutput(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): AiResponse {
        $this->assertDestinationPermitted('structured_output');

        $free = [$prompt];
        $free = $this->appendSystemPrompt($free, $options);

        $redacted = $this->apply(
            'structured_output',
            $free,
            $this->structuredSpansOf('structured_output', $options, $schema),
        );

        return $this->inner->structuredOutput(
            $redacted[0],
            $schema,
            $this->rebuildOptions($options, $redacted),
        );
    }

    /**
     * Not egress. Delegated so that the guard is transparent to anything reading
     * the provider's identity — including its own coherence check, which is what
     * makes that check a comparison of two independent statements.
     */
    #[Override]
    public function providerName(): string
    {
        return $this->inner->providerName();
    }

    /**
     * Guard the two chat-shaped methods, which take the same payload.
     *
     * @param list<ChatMessage> $messages
     *
     * @return array{0: list<ChatMessage>, 1: AiRequestOptions}
     *
     * @throws AiEgressRefusedException
     */
    private function guardChat(string $operation, array $messages, AiRequestOptions $options): array
    {
        $this->assertDestinationPermitted($operation);

        // Message content and participant name are both free text written by the
        // application; both leave. `toolCallId` is a provider-generated
        // correlation token echoed back verbatim, and masking it would break the
        // correlation it exists for, so it is not scanned — recorded here because
        // it is a hole in the coverage rather than an oversight.
        $free = [];

        foreach ($messages as $message) {
            $free[] = $message->content;
            $free[] = $message->name ?? '';
        }

        $free = $this->appendSystemPrompt($free, $options);

        $redacted = $this->apply($operation, $free, $this->structuredSpansOf($operation, $options, null));

        $rebuilt = [];
        $cursor = 0;

        foreach ($messages as $message) {
            $content = $redacted[$cursor];
            $name = $redacted[$cursor + 1];
            $cursor += 2;

            $rebuilt[] = new ChatMessage(
                role: $message->role,
                content: $content,
                name: $message->name === null ? null : $name,
                toolCallId: $message->toolCallId,
            );
        }

        return [$rebuilt, $this->rebuildOptions($options, $redacted)];
    }

    /**
     * Step 1 and step 2: is this the client we think it is, and may it be
     * reached?
     *
     * @throws AiEgressRefusedException
     */
    private function assertDestinationPermitted(string $operation): void
    {
        $reported = $this->inner->providerName();

        if ($reported !== $this->destination->providerName) {
            $decision = new AiEgressDecision(
                outcome: AiEgressOutcome::Refused,
                destination: $this->destination,
                operation: $operation,
                matches: [],
                reasonCode: AiEgressDecision::REASON_DESTINATION_MISMATCH,
                reason: 'wrapped client reports provider "' . $reported . '"',
            );
            $this->publish($decision);

            throw AiEgressRefusedException::destinationMismatch($decision, $reported);
        }

        if (!$this->policy->permits($this->destination)) {
            $decision = new AiEgressDecision(
                outcome: AiEgressOutcome::Refused,
                destination: $this->destination,
                operation: $operation,
                matches: [],
                reasonCode: AiEgressDecision::REASON_DESTINATION_NOT_PERMITTED,
                reason: 'destination host is not on the allow-list',
            );
            $this->publish($decision);

            throw AiEgressRefusedException::destinationNotPermitted($decision);
        }
    }

    /**
     * Steps 3 and 4: classify everything outbound, then apply the policy.
     *
     * @param list<string> $free       Text that may be masked in place
     * @param list<string> $structured Serialised documents that may not
     *
     * @return list<string> The free text as it should be sent
     *
     * @throws AiEgressRefusedException
     */
    private function apply(string $operation, array $free, array $structured): array
    {
        $freeResults = $this->classify($operation, $free);
        $structuredResults = $this->classify($operation, $structured);

        $freeMatches = self::matchesOf($freeResults);
        $structuredMatches = self::matchesOf($structuredResults);
        $allMatches = [...$freeMatches, ...$structuredMatches];

        if ($allMatches === []) {
            $this->reportInspectedAndClean($operation);

            return $free;
        }

        return match ($this->policy->onSensitiveData) {
            DlpAction::Block => $this->refuseBlocked($operation, $allMatches),
            DlpAction::Redact => $this->redact($operation, $free, $freeResults, $allMatches, $structuredMatches),
            DlpAction::Alert => $this->alert($operation, $free, $allMatches),
        };
    }

    /**
     * @param list<string> $texts
     *
     * @return list<DlpScanResult>
     *
     * @throws AiEgressRefusedException
     */
    private function classify(string $operation, array $texts): array
    {
        $results = [];

        foreach ($texts as $text) {
            try {
                $result = $this->classifier->scan($text);
            } catch (Throwable $failure) {
                // A pattern validator is application code and may raise. Bytes
                // whose verdict raised are unclassified bytes.
                throw $this->refuseUnclassified(
                    $operation,
                    'the classifier raised ' . $failure::class . ': ' . $failure->getMessage(),
                );
            }

            if (!$result->status->isConclusive()) {
                // The scanner did not examine these bytes, or gave up part way.
                // A partial result is refused too: the matches a failed scan does
                // carry are a lower bound, and acting on a lower bound as though
                // it were the answer is the permissive reading of an unknown.
                throw $this->refuseUnclassified(
                    $operation,
                    'scan status: ' . $result->status->value,
                );
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param list<string>        $free
     * @param list<DlpScanResult> $freeResults
     * @param list<DlpMatch>      $allMatches
     * @param list<DlpMatch>      $structuredMatches
     *
     * @return list<string>
     *
     * @throws AiEgressRefusedException
     */
    private function redact(
        string $operation,
        array $free,
        array $freeResults,
        array $allMatches,
        array $structuredMatches,
    ): array {
        if ($structuredMatches !== []) {
            // Masking a span inside a JSON schema or a tool definition produces a
            // document that is no longer the document — a corrupted payload sent
            // quietly, which is the defect redaction is supposed to avoid. There
            // is no safe rewrite, so the call is refused instead.
            $decision = new AiEgressDecision(
                outcome: AiEgressOutcome::Refused,
                destination: $this->destination,
                operation: $operation,
                matches: $allMatches,
                reasonCode: AiEgressDecision::REASON_SENSITIVE_DATA_IN_STRUCTURED_FIELD,
                reason: 'classified data in a structured field cannot be masked without corrupting it',
            );
            $this->publish($decision);

            throw AiEgressRefusedException::sensitiveDataInStructuredField($decision);
        }

        $rewritten = [];

        foreach ($freeResults as $index => $result) {
            $rewritten[] = $result->detected ? $result->redactedContent : $free[$index];
        }

        $this->publish(new AiEgressDecision(
            outcome: AiEgressOutcome::Redacted,
            destination: $this->destination,
            operation: $operation,
            matches: $allMatches,
            reasonCode: AiEgressDecision::REASON_NONE,
            reason: 'classified spans were masked before the payload was sent',
        ));

        return $rewritten;
    }

    /**
     * @param list<string>   $free
     * @param list<DlpMatch> $allMatches
     *
     * @return list<string>
     */
    private function alert(string $operation, array $free, array $allMatches): array
    {
        $this->publish(new AiEgressDecision(
            outcome: AiEgressOutcome::Allowed,
            destination: $this->destination,
            operation: $operation,
            matches: $allMatches,
            reasonCode: AiEgressDecision::REASON_NONE,
            reason: 'policy is alert-only: the payload was sent carrying classified data',
        ));

        return $free;
    }

    /**
     * @param list<DlpMatch> $allMatches
     *
     * @throws AiEgressRefusedException
     */
    private function refuseBlocked(string $operation, array $allMatches): never
    {
        $decision = new AiEgressDecision(
            outcome: AiEgressOutcome::Refused,
            destination: $this->destination,
            operation: $operation,
            matches: $allMatches,
            reasonCode: AiEgressDecision::REASON_SENSITIVE_DATA_BLOCKED,
            reason: 'policy is block and the payload carries classified data',
        );
        $this->publish($decision);

        throw AiEgressRefusedException::sensitiveDataBlocked($decision);
    }

    private function refuseUnclassified(string $operation, string $detail): AiEgressRefusedException
    {
        $decision = new AiEgressDecision(
            outcome: AiEgressOutcome::Refused,
            destination: $this->destination,
            operation: $operation,
            matches: [],
            reasonCode: AiEgressDecision::REASON_UNCLASSIFIED_PAYLOAD,
            reason: 'the payload was not classified: ' . $detail,
        );
        $this->publish($decision);

        return AiEgressRefusedException::classifierUnavailable($decision, $detail);
    }

    /**
     * The parts of a request that are documents rather than prose.
     *
     * Tool definitions and JSON schemas leave the deployment like everything
     * else, so they are classified; they are kept apart from the free text
     * because the only honest response to a detection inside one is refusal.
     *
     * @param array<string, mixed>|null $schema
     *
     * @return list<string>
     *
     * @throws AiEgressRefusedException
     */
    private function structuredSpansOf(string $operation, AiRequestOptions $options, ?array $schema): array
    {
        $spans = [];

        try {
            foreach ($options->tools as $tool) {
                $spans[] = json_encode(
                    ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->parameters],
                    JSON_THROW_ON_ERROR,
                );
            }

            if ($schema !== null) {
                $spans[] = json_encode($schema, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable $failure) {
            // A payload the guard cannot serialise is a payload it cannot read,
            // and an unreadable payload is not a safe one.
            throw $this->refuseUnclassified(
                $operation,
                'a structured field could not be serialised for inspection: ' . $failure->getMessage(),
            );
        }

        return $spans;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private function appendSystemPrompt(array $texts, AiRequestOptions $options): array
    {
        // Always appended, even when null, so that the slot index the rebuild
        // reads is a function of the list's shape and not of the option's value.
        $texts[] = $options->systemPrompt ?? '';

        return $texts;
    }

    /**
     * Rebuild the options with the redacted system prompt in place.
     *
     * {@see appendSystemPrompt()} always puts the system prompt in the last slot,
     * for every operation, so the index is a property of the list's shape rather
     * than something each caller has to remember and can get wrong.
     *
     * @param list<string> $redacted
     */
    private function rebuildOptions(AiRequestOptions $options, array $redacted): AiRequestOptions
    {
        if ($options->systemPrompt === null) {
            return $options;
        }

        // array_key_last() rather than count() - 1: the slot is guaranteed by
        // appendSystemPrompt(), but "guaranteed by a sibling method" is not a
        // fact the type system can check, and an arithmetic index on a list the
        // checker believes may be empty is exactly the kind of unproven
        // assumption worth not writing.
        $lastIndex = array_key_last($redacted);

        if ($lastIndex === null) {
            return $options;
        }

        $systemPrompt = $redacted[$lastIndex];

        if ($systemPrompt === $options->systemPrompt) {
            return $options;
        }

        return new AiRequestOptions(
            temperature: $options->temperature,
            maxTokens: $options->maxTokens,
            systemPrompt: $systemPrompt,
            model: $options->model,
            timeoutSeconds: $options->timeoutSeconds,
            tools: $options->tools,
            responseFormat: $options->responseFormat,
        );
    }

    /**
     * Announce a decision to both channels.
     *
     * The audit seam first, because {@see AiEgressObserverInterface} is
     * application code that may throw, and a refusal that never reached the audit
     * trail because a reporting listener failed is the record an assessor most
     * needs missing.
     */
    private function publish(AiEgressDecision $decision): void
    {
        $this->auditSink?->report($decision->toAuditDecision());
        $this->observer->decided($decision);
    }

    /**
     * Tell the audit seam that this call was inspected and found clean.
     *
     * Reported even though nothing happened, and that is the point: the auditing
     * decorator distinguishes "a control looked and passed it" from "no control
     * reported anything", and records the second as `not_observed`. A guard that
     * stayed silent on its clean calls would make a guarded deployment
     * indistinguishable, in the trail, from one with no egress control at all.
     *
     * The observer is deliberately not told — see the invariant on
     * {@see AiEgressObserverInterface::decided()}.
     */
    private function reportInspectedAndClean(string $operation): void
    {
        $this->auditSink?->report(
            new AiEgressDecision(
                outcome: AiEgressOutcome::Allowed,
                destination: $this->destination,
                operation: $operation,
                matches: [],
                reasonCode: AiEgressDecision::REASON_NONE,
                reason: 'the classifier examined the payload and found nothing',
            )->toAuditDecision(),
        );
    }

    /**
     * @param list<DlpScanResult> $results
     *
     * @return list<DlpMatch>
     */
    private static function matchesOf(array $results): array
    {
        $matches = [];

        foreach ($results as $result) {
            foreach ($result->matches as $match) {
                $matches[] = $match;
            }
        }

        return $matches;
    }
}
