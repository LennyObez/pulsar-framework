<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;

/**
 * A control as a mapping declares it: identifier, requirement text, and how its
 * outcome is determined.
 *
 * There is no status parameter, no public constructor, and no setter. A mapping
 * that wants to say a control is met must supply a probe that can observe it —
 * which is the whole decision, expressed as a type.
 *
 * There is no feature list either, and its removal belongs to the same argument.
 * `frameworkFeatures` was an array of identifiers a mapping author typed —
 * `['tokenization', 'token_vault', 'crypto_keyring']` beside PCI Req 3.4 — that
 * nothing derived, nothing checked and nothing could falsify. It was ADR-0041's
 * "Covered by TokenizationService" in list form, and the report printed it beside
 * the evidence as though the two were the same kind of thing. What covers a
 * control is now exactly what the finding cites: the observations that decided
 * it, each naming the class that produced it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ControlDeclaration
{
    use SealedValue;

    /**
     * @param non-empty-string $id
     */
    private function __construct(
        public string $id,
        public ComplianceFramework $framework,
        public string $title,
        public string $requirement,
        public ?ControlProbeInterface $probe,
        public string $operatorArtefact,
    ) {}

    /**
     * A control whose outcome is computed by running $probe against the
     * deployment.
     *
     * @param non-empty-string $id
     */
    #[NoDiscard]
    public static function probed(
        string $id,
        ComplianceFramework $framework,
        string $title,
        string $requirement,
        ControlProbeInterface $probe,
    ): self {
        return new self($id, $framework, $title, $requirement, $probe, '');
    }

    /**
     * A control discharged outside the software — an approved policy, a signed
     * breach register, a training record, a CI run for the deployed commit.
     *
     * Pulsar can never observe these, and pretending otherwise is how ISO 27001
     * A.5.1 came to be graded from the existence of a CSP config. Such a control
     * carries NO probe (the type says so), never counts toward coverage, and
     * must name the artefact an assessor is to be shown — so the report doubles
     * as that assessor's checklist instead of quietly padding a percentage.
     *
     * @param non-empty-string $id
     * @param non-empty-string $artefact
     */
    #[NoDiscard]
    public static function operatorResponsibility(
        string $id,
        ComplianceFramework $framework,
        string $title,
        string $requirement,
        string $artefact,
    ): self {
        return new self($id, $framework, $title, $requirement, null, $artefact);
    }

    #[NoDiscard]
    public function isProbed(): bool
    {
        return $this->probe !== null;
    }
}
