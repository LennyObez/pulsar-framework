<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;

/**
 * A control as a mapping declares it: identifier, requirement text, the ESTATE it
 * regulates, and how its outcome is determined.
 *
 * There is no status parameter, no public constructor, and no setter. A mapping
 * that wants to say a control is met must supply a probe that can observe it —
 * which is the whole decision, expressed as a type.
 *
 * THE ESTATE IS THE OTHER HALF OF THAT DECISION, and it is the newer half. A
 * probe says which FACTS a control needs; nothing said what those facts had to be
 * ABOUT, so `extension_loaded('sodium')` answered for personal data and an HMAC
 * chain over the framework's own compliance register answered for eleven
 * standards' audit trails. A mapping author reading the standard's text knows
 * what the control regulates, which is exactly the knowledge a probe does not
 * have and cannot acquire; see {@see probed()} and {@see ControlSubject}.
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
     * @param non-empty-string   $id
     * @param ControlSubject|null $subject The estate this control regulates; null only for
     *        an operator-responsibility control, which is never assessed and therefore
     *        never joined against a fact
     */
    private function __construct(
        public string $id,
        public ComplianceFramework $framework,
        public string $title,
        public string $requirement,
        public ?ControlProbeInterface $probe,
        public string $operatorArtefact,
        public ?ControlSubject $subject = null,
    ) {}

    /**
     * A control whose outcome is computed by running $probe against the
     * deployment.
     *
     * THE SUBJECT IS MANDATORY AND IT IS DECLARED HERE, NOT ON THE PROBE. It is
     * the estate the standard's requirement names, and it is what
     * {@see ProbeVerdict::reach()} joins against every fact offered as proof and
     * against the operator's scope assertion. Two reasons it lives on the
     * declaration:
     *
     *  - A probe declaring its own estate would be self-certifying. It would name
     *    the estate of the facts it already requires and then agree with itself,
     *    which is the shape of assurance ADR-0045 spent its whole argument
     *    removing.
     *  - One probe serves controls about different estates.
     *    `DataErasureProbe` answers CCPA 1798.105, which is about personal data,
     *    and SOC 2 C1.2, which is about confidential information; before this
     *    parameter existed, `scope.processes_personal_data = false` retired both.
     *    Nothing on the probe can tell those two controls apart, because it is the
     *    same probe.
     *
     * Adding a required parameter to an `#[Api]` factory during the RC phase is a
     * breaking change, and it is taken rather than avoided, for the reason
     * ADR-0045 §1 gives about deleting `ControlStatus`: an optional subject would
     * be a subject a mapping can forget, and a half-enforced invariant here is
     * equivalent to none. The compiler converts every mapping in the tree by
     * force. See ADR-0062.
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
        ControlSubject $subject,
    ): self {
        return new self($id, $framework, $title, $requirement, $probe, '', $subject);
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
     * IT NAMES NO ESTATE EITHER, and the omission is the same statement one level
     * on: an estate exists so that facts can be joined against it, and nothing is
     * ever joined here. A checklist control that carried one would be inviting the
     * next reader to wonder why no fact ever matched.
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

    /**
     * The estate this control regulates, for the assessment that is about to run.
     *
     * `$subject` is non-null for exactly the declarations `$probe` is non-null for
     * — both factories set the pair together and the constructor is private — but
     * PHP cannot express "these two are null together", so the pairing is asserted
     * here rather than assumed at the one call site that needs it. A declaration
     * that reached this method without an estate is a defect in this class, and it
     * is surfaced as one instead of being defaulted to an estate that would then
     * decide a control.
     *
     * @throws InadmissibleEvidenceException when the declaration carries no probe,
     *         and so is a checklist item that is never assessed
     */
    #[NoDiscard]
    public function assessedSubject(): ControlSubject
    {
        return $this->subject
            ?? throw InadmissibleEvidenceException::forUnassessedSubject($this->id);
    }
}
