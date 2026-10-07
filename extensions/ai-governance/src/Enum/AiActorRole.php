<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Enum;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Which set of EU AI Act obligations a deployment bears for one AI system.
 *
 * THIS IS THE QUESTION THE ACT ANSWERS BEFORE ANY OTHER, and until now the
 * extension could not express it. The Act does not attach duties to an AI system;
 * it attaches them to a person in a role with respect to that system. A provider
 * operates the risk management system of Article 9, holds the Annex IV technical
 * documentation of Article 11, and carries the post-market monitoring plan of
 * Article 72(3). A deployer does none of those things: Article 26 asks it to use
 * the system according to the instructions, to assign human oversight to natural
 * persons with the necessary competence and authority, to ensure input data is
 * relevant, to monitor operation, and to keep the automatically generated logs.
 * The two lists barely overlap.
 *
 * WHAT WENT WRONG WITHOUT IT. {@see \Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate}
 * demanded an impact assessment, a model card and a monitoring hook from every
 * high-risk model, whoever was deploying it. For a provider that is right. For a
 * bank deploying a third-party credit-scoring model it is wrong twice over: it
 * demands Annex IV documentation the deployer does not draw up, and it demands
 * none of Article 26, so the gate refused a deployment for failing duties it did
 * not owe while passing it on the duties it did. A gate that cannot tell which
 * set it is enforcing is not enforcing either.
 *
 * ARTICLE 3(3) AND 3(4) ARE THE DEFINITIONS. A provider develops an AI system, or
 * has one developed, and places it on the market or puts it into service under
 * its own name or trademark. A deployer uses an AI system under its authority,
 * except in a personal non-professional activity.
 *
 * ARTICLE 25 IS WHY {@see ProviderAndDeployer} IS NOT A CONVENIENCE CASE. A
 * distributor, importer, deployer or third party BECOMES a provider of a
 * high-risk system, and takes on the Article 16 obligations in full, where it
 * puts its name or trademark on a system already on the market, makes a
 * substantial modification to one, or modifies the intended purpose of a system
 * such that it becomes high-risk. Nothing in code can observe that any of those
 * three things happened — they are facts about a commercial and engineering
 * relationship — so this enum does not infer the transition. What it provides is
 * a case to declare once it has occurred, and a deployment that fine-tunes a
 * bought-in model on its own data has almost certainly occurred into it.
 *
 * THERE IS NO `Unknown` CASE, deliberately. A role that has not been declared is
 * ABSENT, spelled `null` wherever a role is held, and absence is a different
 * thing from a declared uncertainty: the gate refuses a high-risk deployment
 * whose role is absent, naming both sets, rather than picking one. An `Unknown`
 * case would be a value a store could hold and a report could print, which is how
 * "we never decided" comes to look like a decision.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum AiActorRole: string
{
    /** Article 3(3): develops or has developed, and places on the market or puts into service. */
    case Provider = 'provider';

    /** Article 3(4): uses the system under its own authority, professionally. */
    case Deployer = 'deployer';

    /**
     * Both at once.
     *
     * The common case for an organisation that builds and runs its own system,
     * and the case Article 25 moves a deployer into when it puts its name on a
     * bought-in high-risk system, substantially modifies one, or repurposes a
     * system into the high-risk tier.
     */
    case ProviderAndDeployer = 'provider_and_deployer';

    /**
     * Whether the pre-market provider duties of Chapter III Section 2 apply.
     *
     * Articles 9, 11 and 72(3) — the three this extension models artefacts for —
     * together with everything else Article 16 lists.
     */
    #[NoDiscard]
    public function bearsProviderObligations(): bool
    {
        return match ($this) {
            self::Provider, self::ProviderAndDeployer => true,
            self::Deployer => false,
        };
    }

    /**
     * Whether the Article 26 deployer duties apply.
     */
    #[NoDiscard]
    public function bearsDeployerObligations(): bool
    {
        return match ($this) {
            self::Deployer, self::ProviderAndDeployer => true,
            self::Provider => false,
        };
    }

    /**
     * The wording an assessor is shown against a declared role.
     *
     * Every case names the article that defines it and the article that carries
     * its duties, because a role recorded as a bare word is an assertion an
     * assessor cannot check against anything.
     */
    #[NoDiscard]
    public function obligationBasis(): string
    {
        return match ($this) {
            self::Provider => 'Provider (Article 3(3)): bears the Article 16 provider obligations, '
                . 'including the risk management system (Article 9), the Annex IV technical '
                . 'documentation (Article 11) and the post-market monitoring plan (Article 72(3)).',
            self::Deployer => 'Deployer (Article 3(4)): bears the Article 26 deployer obligations, '
                . 'including use in accordance with the instructions for use, assignment of human '
                . 'oversight to natural persons with the necessary competence, training and '
                . 'authority (Article 26(2)), and retention of the automatically generated logs '
                . 'for at least six months (Article 26(6)).',
            self::ProviderAndDeployer => 'Provider and deployer at once: bears the Article 16 '
                . 'provider obligations and the Article 26 deployer obligations together. This is '
                . 'also the position Article 25 moves a deployer into once it puts its name on a '
                . 'high-risk system already on the market, substantially modifies one, or modifies '
                . 'the intended purpose of a system so that it becomes high-risk.',
        };
    }
}
