<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Override;
use Pulsar\Api\Api;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;

use function array_map;
use function implode;
use function sprintf;

/**
 * Reports the password-length floor the deployment actually enforces against the
 * one the active compliance profile requires.
 *
 * This check exists because {@see ComplianceProfile::$passwordMinLength} was
 * resolved on every boot — the strictest minimum across every enabled framework,
 * computed from the frameworks' own requirement objects — and then read by
 * nothing. A resolved number that reaches no wiring, no validator and no report
 * is a promise to an operator that nobody keeps.
 *
 * What can honestly be established here is narrower than the field suggests, and
 * the verdicts say so rather than rounding up:
 *
 *  - The framework enforces exactly one floor of its own. {@see \Pulsar\Live\Auth\SignupForm} builds
 *    its `min_length` rule from `max(PasswordHasherInterface::MIN_LENGTH, …)`, so
 *    the shortest password Pulsar's own signup surface accepts is
 *    {@see PasswordHasherInterface::MIN_LENGTH}. A profile asking for no more than
 *    that is satisfied by code in this tree, and that is a PASS.
 *  - A profile asking for more is satisfied only if every password-accepting
 *    surface raises the minimum, and most of those surfaces belong to the
 *    application. Nothing in the container reveals what an application's own
 *    signup form validates, so nothing was established — a SKIP, carrying the
 *    gap and the two calls that close it.
 *
 * It is deliberately never a FAIL. {@see RuntimeVerifier::checkFipsMode()} draws
 * the same line for the same reason: a FAIL refuses the boot under compliance
 * strict mode, and refusing every PCI-DSS deployment's boot over a control this
 * process cannot see would punish the deployments that DO enforce twelve
 * characters exactly as hard as the ones that do not. A skip reports
 * `present = false` to the evidence gatherer just as a fail does, so no control is
 * credited either way; only the boot-refusal semantics differ.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PasswordPolicyCheck implements ComplianceCheckInterface
{
    /**
     * Named as a string rather than imported: the only use is to tell an operator
     * which call raises the minimum, and a compliance check has no business
     * depending on the Live UI module to print a class name.
     */
    private const string SIGNUP_FORM = 'Pulsar\Live\Auth\SignupForm';

    /**
     * @param bool $constrained Whether at least one ENABLED framework actually mandates a
     *        password minimum, from {@see \Pulsar\Compliance\ComplianceProfileResolver::constraints()}.
     *        The resolved profile always carries a number — its own baseline default when no
     *        framework asks for one — and reporting on that default would attribute a limit to
     *        frameworks that never imposed it.
     */
    public function __construct(
        private ComplianceProfile $profile,
        private bool $constrained,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'auth.password_min_length';
    }

    #[Override]
    public function description(): string
    {
        return 'Minimum password length enforced by the deployment against the length the '
            . 'active compliance profile requires.';
    }

    #[Override]
    public function domain(): ComplianceCheckDomain
    {
        return ComplianceCheckDomain::Authentication;
    }

    #[Override]
    public function execute(): CheckResult
    {
        if (!$this->constrained) {
            return CheckResult::skip(
                $this->id(),
                'No enabled framework mandates a minimum password length, so the profile carries '
                    . 'only the resolver baseline and nothing is assessed against it.',
                $this->domain(),
            );
        }

        $required = $this->profile->passwordMinLength;
        $frameworkFloor = PasswordHasherInterface::MIN_LENGTH;

        if ($required <= $frameworkFloor) {
            return CheckResult::pass(
                $this->id(),
                sprintf(
                    'The active profile requires %d characters and Pulsar\'s own signup surface '
                        . 'refuses anything shorter than %d, so the requirement is met by the '
                        . 'framework itself.',
                    $required,
                    $frameworkFloor,
                ),
                $this->domain(),
                [
                    'required_min_length: ' . $required,
                    'framework_floor: ' . $frameworkFloor,
                    'enforced_by: ' . self::SIGNUP_FORM . '::rules()',
                    'frameworks: ' . $this->frameworkList(),
                ],
            );
        }

        return CheckResult::skip(
            $this->id(),
            sprintf(
                'Not established: the active profile requires %d characters, %d more than the %d '
                    . 'Pulsar\'s own signup surface enforces, and a password minimum above the '
                    . 'framework floor lives on application surfaces this process cannot inspect. '
                    . 'Raise it with %s::requireAtLeast(%d) on every form that accepts a password, '
                    . 'or set auth UI password_min_length to %d. Frameworks requiring it: %s.',
                $required,
                $required - $frameworkFloor,
                $frameworkFloor,
                self::SIGNUP_FORM,
                $required,
                $required,
                $this->frameworkList(),
            ),
            $this->domain(),
        );
    }

    private function frameworkList(): string
    {
        return implode(', ', array_map(
            static fn(ComplianceFramework $f): string => $f->value,
            $this->profile->enabledFrameworks,
        ));
    }
}
