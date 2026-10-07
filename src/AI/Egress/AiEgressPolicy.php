<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Support\Coerce;

use function in_array;
use function strtolower;

/**
 * What an operator permits to leave this deployment through an AI client.
 *
 * Two independent questions, because the answers are independent: *may this
 * endpoint be reached at all*, and *what happens when the payload carries
 * personal data*. A hospital that runs Ollama on its own hardware answers the
 * first with one host and the second with anything it likes; the same hospital
 * pointed at a hosted endpoint is refused before the second question is asked.
 *
 * BOTH DEFAULTS ARE THE REFUSING ONES.
 *
 * `$allowedHosts` defaults to the empty list, which permits nothing. The
 * alternative — an empty list meaning "unrestricted" — reads the same in a config
 * file that forgot the key as in one that deliberately opened everything, and the
 * deployment that forgets is precisely the one this guard is for. An operator who
 * wants a hosted endpoint names it.
 *
 * `$onSensitiveData` defaults to {@see DlpAction::Block}: a payload carrying
 * classified data is refused rather than redacted. Redaction is the *less*
 * conservative choice even though it sounds careful — it still sends a request,
 * still bills, still leaves a trace at the endpoint, and it changes what the
 * model was asked in a way the caller did not write. Blocking sends nothing.
 * {@see DlpAction::Redact} and {@see DlpAction::Alert} are both available and
 * both legitimate; they have to be chosen.
 *
 * The consequence of the Block default is worth stating because operators will
 * meet it on day one: the shipped pattern set includes IPv4 addresses and a broad
 * API-key shape, so a prompt discussing a server address is refused by default.
 * That is the intended direction of error, and it is tuned by editing the
 * registry's patterns — one classifier, adjusted in one place — not by loosening
 * this policy.
 *
 * WHY THE ACTION VOCABULARY IS {@see DlpAction} AND NOT A NEW ENUM. The framework
 * has exactly one classifier that reads free text
 * ({@see \Pulsar\Security\Dlp\SensitivePatternRegistry}); the other four
 * classification vocabularies in the tree — `ControlSubject`,
 * `ClassificationLevel`, `DataClassification`, `ClassificationTag` — describe a
 * declared field or a compliance estate and have nothing to say about an
 * unstructured prompt. Reusing that classifier's own action vocabulary keeps the
 * count at one. A second enum meaning almost the same three things is how two
 * policies come to disagree.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiEgressPolicy
{
    /**
     * @param list<string> $allowedHosts     Hosts that may be reached, matched
     *                                       exactly and case-insensitively.
     *                                       Empty permits nothing
     * @param DlpAction    $onSensitiveData  What to do when the classifier finds
     *                                       something in an outbound payload
     */
    public function __construct(
        public array $allowedHosts = [],
        public DlpAction $onSensitiveData = DlpAction::Block,
    ) {}

    /**
     * Whether this destination may be reached.
     *
     * Exact host equality, deliberately. Wildcards were considered and left out:
     * the shapes an operator would reach for (`*.openai.com`, and from there
     * `*.com`) turn a list of endpoints into a pattern language, and a pattern
     * language on an egress allow-list fails in the permissive direction. A
     * deployment that needs three hosts names three hosts.
     *
     * Ports are not matched. A host is the residency question — which
     * organisation's machines receive the bytes — and two ports on one host are
     * the same organisation.
     */
    #[NoDiscard]
    public function permits(AiDestination $destination): bool
    {
        return in_array($destination->host, $this->allowedHosts, true);
    }

    /**
     * Build from a config array.
     *
     * Entries that are not strings are dropped rather than coerced, through the
     * framework's own {@see Coerce::listOfString()}. An allow-list is a security
     * control, and `(string) ['api.openai.com']` — or any other salvage of a
     * malformed entry — would put a host on it that nobody wrote.
     *
     * @param array{
     *     allowed_hosts?: mixed,
     *     on_sensitive_data?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $hosts = [];

        foreach (Coerce::listOfString($data['allowed_hosts'] ?? null) as $host) {
            if ($host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return new self(
            allowedHosts: $hosts,
            onSensitiveData: isset($data['on_sensitive_data'])
                ? DlpAction::from($data['on_sensitive_data'])
                : DlpAction::Block,
        );
    }
}
