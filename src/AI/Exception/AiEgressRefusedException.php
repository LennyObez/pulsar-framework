<?php

declare(strict_types=1);

namespace Pulsar\AI\Exception;

use NoDiscard;
use Pulsar\AI\Egress\AiEgressDecision;
use Pulsar\Api\Api;
use RuntimeException;

use function count;
use function implode;
use function sprintf;

/**
 * An outbound AI call was refused before any transport ran.
 *
 * NOT a subclass of {@see AiException}, and the separation is deliberate rather
 * than incidental — `AiException` is `final`, and it should stay that way. An
 * application that wraps AI calls in `catch (AiException)` is catching transport
 * trouble: a timeout, a 429, a malformed response. Every one of those is worth
 * retrying, and a retry loop that also swallowed this exception would send the
 * refused payload again on the next attempt, or report a policy refusal to the
 * user as a provider outage. A refusal is not a failure to reach the provider; it
 * is the deployment declining to.
 *
 * The message never quotes what was found. {@see $decision} carries the masked
 * matches and their kinds, which is what an operator needs and as far as an
 * exception — which reaches logs, error pages and trackers — should ever take
 * personal data.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class AiEgressRefusedException extends RuntimeException
{
    /**
     * The decision that produced this refusal, or null when the guard could not
     * get far enough to form one.
     *
     * The null case is exactly {@see undeterminableDestination()}: a base URL
     * that yields no host has no destination, and a decision cannot be built
     * about a destination that does not exist.
     */
    public readonly ?AiEgressDecision $decision;

    private function __construct(string $message, ?AiEgressDecision $decision)
    {
        parent::__construct($message);

        $this->decision = $decision;
    }

    /**
     * The destination is not on the operator's allow-list.
     */
    #[NoDiscard]
    public static function destinationNotPermitted(AiEgressDecision $decision): self
    {
        return new self(
            sprintf(
                'AI egress to %s is not permitted: the host is not in the configured allowed_hosts. '
                . 'No request was constructed.',
                $decision->destination->describe(),
            ),
            $decision,
        );
    }

    /**
     * The wrapped client does not answer to the name the destination was
     * declared for.
     */
    #[NoDiscard]
    public static function destinationMismatch(AiEgressDecision $decision, string $reportedProviderName): self
    {
        return new self(
            sprintf(
                'AI egress refused: the guard was configured for provider "%s" at %s but wraps a client '
                . 'reporting "%s". One of the two is misconfigured and the guard cannot tell which, so '
                . 'nothing was sent.',
                $decision->destination->providerName,
                $decision->destination->host,
                $reportedProviderName,
            ),
            $decision,
        );
    }

    /**
     * The classifier could not answer, so the payload is unclassified.
     */
    #[NoDiscard]
    public static function classifierUnavailable(AiEgressDecision $decision, string $detail): self
    {
        return new self(
            sprintf(
                'AI egress to %s refused: the sensitive-data classifier did not examine the payload (%s). '
                . 'An unclassified payload is not a payload known to be safe, so nothing was sent.',
                $decision->destination->describe(),
                $detail,
            ),
            $decision,
        );
    }

    /**
     * The payload carries classified data and the policy is to block.
     */
    #[NoDiscard]
    public static function sensitiveDataBlocked(AiEgressDecision $decision): self
    {
        $types = [];

        foreach ($decision->sensitiveDataTypes() as $type) {
            $types[] = $type->value;
        }

        return new self(
            sprintf(
                'AI egress to %s refused: the payload carries %d classified span(s) of kind [%s]. '
                . 'Nothing was sent.',
                $decision->destination->describe(),
                count($decision->matches),
                implode(', ', $types),
            ),
            $decision,
        );
    }

    /**
     * Classified data was found somewhere redaction cannot repair.
     */
    #[NoDiscard]
    public static function sensitiveDataInStructuredField(AiEgressDecision $decision): self
    {
        return new self(
            sprintf(
                'AI egress to %s refused: classified data was found in a structured field (a tool '
                . 'definition or a JSON schema), where masking would corrupt the document rather than '
                . 'protect it. Nothing was sent.',
                $decision->destination->describe(),
            ),
            $decision,
        );
    }

    /**
     * No host could be derived from the configured base URL.
     */
    #[NoDiscard]
    public static function undeterminableDestination(string $providerName, string $baseUrl): self
    {
        return new self(
            sprintf(
                'AI egress refused: no host could be derived from the base URL configured for provider '
                . '"%s" (%s). A destination that cannot be named cannot be checked against the '
                . 'allow-list.',
                $providerName,
                $baseUrl,
            ),
            null,
        );
    }
}
