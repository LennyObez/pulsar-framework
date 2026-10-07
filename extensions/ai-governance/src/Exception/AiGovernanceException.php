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

    /**
     * A stored governance row cannot produce the record it is supposed to hold.
     *
     * Raised rather than repaired. Every DTO in this extension declares which of
     * its fields may not be blank, and a durable store that substituted a
     * placeholder for a corrupt column would hand an assessor a governance record
     * the deployment never wrote — which is worse than the read failing, because
     * a failed read is visible and a fabricated record is not.
     */
    public static function corruptGovernanceRecord(string $table, string $column): self
    {
        return new self(sprintf(
            'The AI governance record in %s holds a value in "%s" that cannot produce the documented '
            . 'information ISO 42001:2023 Clause 7.5 requires. The row is reported rather than '
            . 'repaired: a substituted value would be a governance record this deployment never wrote.',
            $table,
            $column,
        ));
    }

    /**
     * A durable store was configured and the deployment bound no database.
     *
     * Named separately from a generic container failure because the remedy is a
     * configuration key rather than a missing service: an operator either points
     * the store at a connection or states, in `config/ai-governance.php`, that
     * this deployment keeps its AI management system record in memory and
     * therefore retains nothing.
     */
    public static function durableStoreNeedsConnection(string $configKey): self
    {
        return new self(sprintf(
            'ai_governance.%s is set to "database" and this deployment has no database connection '
            . 'bound. ISO 42001:2023 Clause 7.5 asks for documented information, so the store will '
            . 'not silently fall back to memory: configure a connection, or set this key to "memory" '
            . 'and accept that the record does not survive a restart.',
            $configKey,
        ));
    }

    /**
     * A configured actor role names something the Act does not define.
     *
     * Loud rather than silent, and deliberately not read as "unstated". Which
     * role a deployment holds decides WHICH obligations apply to it, so a typo in
     * that key must not resolve to the state in which the extension enforces
     * neither set - that would turn a misspelling into an exemption.
     *
     * @param list<string> $known
     */
    public static function unknownActorRole(string $value, array $known): self
    {
        return new self(sprintf(
            'ai_governance.actor_role is set to "%s", which is not a role the EU AI Act '
            . '(Regulation (EU) 2024/1689) defines. A provider (Article 3(3)) and a deployer '
            . '(Article 3(4)) bear different obligations, so this key cannot be guessed at. '
            . 'Use one of: %s; or remove the key and declare the role per system.',
            $value,
            implode(', ', $known),
        ));
    }

    /**
     * An oversight record names no system.
     *
     * Article 26(2) assigns oversight of a particular high-risk system to
     * particular people. An assignment naming no system is a statement that
     * someone oversees something.
     */
    public static function oversightModelRequired(): self
    {
        return new self(
            'A human oversight record must name the AI system it concerns. EU AI Act '
            . '(Regulation (EU) 2024/1689) Article 26(2) assigns oversight of a particular '
            . 'high-risk system, so a record naming none cannot be shown to discharge it.',
        );
    }

    /**
     * An oversight record names no person.
     *
     * Article 26(2) says NATURAL PERSONS. The record that nobody in particular
     * oversees a system is the state the Article was written against.
     */
    public static function oversightOverseerRequired(string $modelId): self
    {
        return new self(sprintf(
            'Human oversight of AI system "%s" must name the natural person who exercises it. EU AI '
            . 'Act (Regulation (EU) 2024/1689) Article 26(2) requires oversight to be assigned to '
            . 'natural persons; an unnamed assignment records that nobody in particular is '
            . 'responsible.',
            $modelId,
        ));
    }

    /**
     * An assignment claims competence without stating its basis.
     */
    public static function oversightCompetenceRequired(string $modelId, string $overseerId): self
    {
        return new self(sprintf(
            'The assignment of "%s" to oversee AI system "%s" must state what makes that person '
            . 'competent and trained for it. EU AI Act (Regulation (EU) 2024/1689) Article 26(2) '
            . 'requires the necessary competence and training, and a claim with no stated basis is '
            . 'not evidence an assessor can check.',
            $overseerId,
            $modelId,
        ));
    }

    /**
     * An assignment claims authority without stating its basis.
     *
     * Separate from competence because Article 26(2) lists them separately and
     * they come apart in practice: a clinician may be wholly competent to judge a
     * recommendation and hold no mandate to stop the system producing them.
     */
    public static function oversightAuthorityRequired(string $modelId, string $overseerId): self
    {
        return new self(sprintf(
            'The assignment of "%s" to oversee AI system "%s" must state the authority under which '
            . 'that person may act against the running system. EU AI Act (Regulation (EU) 2024/1689) '
            . 'Article 26(2) requires the necessary authority as well as the necessary competence, '
            . 'and they are not the same thing.',
            $overseerId,
            $modelId,
        ));
    }

    /**
     * An assignment gives a person nothing they can do to the system.
     *
     * Not the same as withholding one of the two action capacities: one person
     * need not hold both, because Article 14(4) asks that the oversight measures
     * enable those actions rather than that every individual can take all of
     * them. What is refused here is an assignment conferring none of them, which
     * describes an observer.
     */
    public static function oversightConfersNoAction(string $modelId, string $overseerId): self
    {
        return new self(sprintf(
            'The assignment of "%s" to oversee AI system "%s" confers no capacity that person can '
            . 'act with. EU AI Act (Regulation (EU) 2024/1689) Article 14(4)(d) and (e) name the two '
            . 'that do — disregard, override or reverse the output, and intervene in or interrupt '
            . 'the operation — and an assignment granting neither describes someone who watches the '
            . 'system rather than someone who oversees it.',
            $overseerId,
            $modelId,
        ));
    }

    /**
     * An intervention record carries no identifier.
     */
    public static function interventionIdRequired(): self
    {
        return new self(
            'A human oversight intervention must carry an identifier. The register is evidence that '
            . 'oversight was exercised on particular occasions, and an occasion nothing can name '
            . 'cannot be produced when it is asked for.',
        );
    }

    /**
     * An override was recorded with no reason given.
     */
    public static function interventionRationaleRequired(string $interventionId, string $modelId): self
    {
        return new self(sprintf(
            'Intervention "%s" over AI system "%s" must state why the overseer acted. An override '
            . 'with no recorded reason is indistinguishable from a mis-click, and where it reversed '
            . 'a decision affecting a person, that person contests it under GDPR Article 22(3) '
            . 'against this sentence.',
            $interventionId,
            $modelId,
        ));
    }

    /**
     * An intervention links to a decision it cannot name.
     */
    public static function interventionDecisionBlank(string $interventionId): self
    {
        return new self(sprintf(
            'Intervention "%s" carries a blank decision id. Pass null where the override concerned '
            . 'no single decision - an interruption of the system as a whole does not - and a '
            . 'decision id where it concerned one. An empty string is a broken link dressed as an '
            . 'absent one.',
            $interventionId,
        ));
    }

    /**
     * Someone with no assignment over a system recorded an override of it.
     *
     * Refused rather than recorded, because the register exists to show that
     * Article 26(2) oversight was exercised. An override by a person the
     * deployment never assigned is evidence that someone touched the system, not
     * that its oversight arrangement works.
     */
    public static function interventionWithoutAssignment(string $modelId, string $overseerId): self
    {
        return new self(sprintf(
            '"%s" recorded an intervention over AI system "%s" without holding an oversight '
            . 'assignment for it. EU AI Act (Regulation (EU) 2024/1689) Article 26(2) requires '
            . 'oversight to be assigned to named natural persons with the necessary competence and '
            . 'authority; an override by an unassigned person is not evidence that the arrangement '
            . 'works. Assign them first, or record the event through the audit trail instead.',
            $overseerId,
            $modelId,
        ));
    }

    /**
     * An assigned person exercised a capacity their assignment does not confer.
     */
    public static function interventionCapabilityNotConferred(
        string $modelId,
        string $overseerId,
        string $capability,
    ): self {
        return new self(sprintf(
            '"%s" recorded an intervention over AI system "%s" exercising "%s", which their '
            . 'oversight assignment does not confer. EU AI Act (Regulation (EU) 2024/1689) '
            . 'Article 14(4) ties each oversight action to a capacity the assignment must give the '
            . 'person; recording the action anyway would report a capacity nobody granted.',
            $overseerId,
            $modelId,
            $capability,
        ));
    }
}
