<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Exception;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;

use function implode;
use function sprintf;

/**
 * Domain exception for AI governance lifecycle failures.
 *
 * Static factories expose the exact failure mode so callers can pattern-match
 * on semantic intent (model not found, deployment gate rejection, invalid
 * state transition) without string-parsing exception messages.
 *
 * Extends `InvalidArgumentException` because every failure mode below
 * represents a caller bug — referencing a model id that does not exist,
 * attempting an illegal state transition, deploying without satisfying
 * the configured gates. None of these are runtime failures of the AI
 * subsystem itself, so consumers handle them like any other PHP
 * argument-shape exception.
 * @api
 */
#[Api(since: '1.0.0')]
final class AiGovernanceException extends InvalidArgumentException
{
    public static function modelNotFound(string $modelId): self
    {
        return new self(sprintf('AI model "%s" not found in registry', $modelId));
    }

    /**
     * @param list<string> $failures
     */
    public static function deploymentBlocked(string $modelId, array $failures): self
    {
        return new self(sprintf(
            'Deployment of model "%s" blocked by gate failures: %s',
            $modelId,
            implode('; ', $failures),
        ));
    }

    public static function invalidStatusTransition(
        string $modelId,
        string $fromStatus,
        string $toStatus,
    ): self {
        return new self(sprintf(
            'Invalid status transition for model "%s": %s → %s',
            $modelId,
            $fromStatus,
            $toStatus,
        ));
    }

    public static function rollbackFromNonProduction(string $modelId, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot rollback model "%s": current status is "%s", expected "production"',
            $modelId,
            $currentStatus,
        ));
    }

    public static function modelAlreadyRegistered(string $modelId): self
    {
        return new self(sprintf('AI model "%s" is already registered', $modelId));
    }

    /**
     * A model classified as an EU AI Act Article 5 prohibited practice was
     * pushed towards production status.
     *
     * Article 5 bans the practice rather than conditioning it, so this failure
     * names no remedial artefact: there is none. The model is reclassified, or
     * it is retired.
     */
    public static function prohibitedPractice(string $modelId): self
    {
        return new self(sprintf(
            'AI model "%s" is classified "%s" and cannot hold production status: '
            . 'EU AI Act (Regulation (EU) 2024/1689) Article 5 prohibits these practices outright, '
            . 'so no impact assessment, documentation or monitoring unblocks it. '
            . 'Reclassify the model or retire it.',
            $modelId,
            AiModelRiskLevel::Unacceptable->value,
        ));
    }

    public static function consentRequired(string $datasetId): self
    {
        return new self(sprintf(
            'Cannot record provenance for dataset "%s": consent is required for all training data',
            $datasetId,
        ));
    }

    /**
     * A surface interacts with people, claims no exemption that reaches Article
     * 50(1), and carries no notice.
     *
     * Article 50 has applied since 2 August 2026 and the digital omnibus that
     * deferred Chapter III did not move it, so this is a live obligation rather
     * than a forward-looking one.
     */
    public static function disclosureRequired(string $surfaceId): self
    {
        return new self(sprintf(
            'AI transparency surface "%s" interacts with natural persons and claims no exemption '
            . 'that discharges it, so EU AI Act (Regulation (EU) 2024/1689) Article 50(1) requires '
            . 'a disclosure notice. Provide an AiInteractionDisclosure, or declare the exemption '
            . 'relied on and be ready to justify it.',
            $surfaceId,
        ));
    }

    /**
     * The law-enforcement exemption is claimed by a surface Article 50(1)
     * expressly carves back out of it.
     */
    public static function exemptionCarvedBack(string $surfaceId): self
    {
        return new self(sprintf(
            'AI transparency surface "%s" claims the law-enforcement exemption while being available '
            . 'to the public to report a criminal offence. EU AI Act (Regulation (EU) 2024/1689) '
            . 'Article 50(1) excludes exactly that case from the exemption, so the disclosure duty '
            . 'stands. Withdraw the claim or mark the surface as not publicly reporting.',
            $surfaceId,
        ));
    }

    public static function surfaceIdRequired(): self
    {
        return new self(
            'An AI transparency surface must be identified: a mark or policy naming no surface '
            . 'cannot be traced back to what produced it, which is what makes an Article 50(2) '
            . 'mark corroborable rather than merely present.',
        );
    }

    public static function disclosureNoticeEmpty(): self
    {
        return new self(
            'An Article 50(1) disclosure must carry the sentence a person is shown. An empty notice '
            . 'is not information given "in a clear and distinguishable manner" (Article 50(5)); it '
            . 'is a record that nobody was told anything.',
        );
    }

    public static function disclosureLocaleEmpty(): self
    {
        return new self(
            'An Article 50(1) disclosure must state the language it is written in. A notice a person '
            . 'cannot read is not clear to that person, and Article 50(5) requires the information to '
            . 'meet the applicable accessibility requirements.',
        );
    }

    public static function surfaceNotDeclared(string $surfaceId): self
    {
        return new self(sprintf(
            'Cannot mark output for AI transparency surface "%s": no policy was declared for it. '
            . 'An EU AI Act (Regulation (EU) 2024/1689) Article 50(2) mark is evidence only if it '
            . 'traces to a declared position; declare the surface before marking its output.',
            $surfaceId,
        ));
    }

    public static function markRequiresModel(string $kind): self
    {
        return new self(sprintf(
            'A synthetic-content mark for %s output must name the model that produced it. EU AI Act '
            . '(Regulation (EU) 2024/1689) Article 50(2) requires the marking to be detectable and '
            . 'machine-readable; a mark naming no model leaves an assessor an assertion with nothing '
            . 'to check it against.',
            $kind,
        ));
    }
}
