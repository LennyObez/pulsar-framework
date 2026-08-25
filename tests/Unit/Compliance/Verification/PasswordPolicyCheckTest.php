<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\PasswordPolicyCheck;

use function str_contains;

#[CoversClass(PasswordPolicyCheck::class)]
final class PasswordPolicyCheckTest extends TestCase
{
    #[Test]
    public function passesWhenTheFrameworkFloorAlreadyMeetsTheRequirement(): void
    {
        $result = new PasswordPolicyCheck($this->profile(minLength: 8), constrained: true)->execute();

        self::assertSame(CheckStatus::Pass, $result->status);
        self::assertSame('auth.password_min_length', $result->checkId);
        self::assertSame(ComplianceCheckDomain::Authentication, $result->domain);
    }

    #[Test]
    public function doesNotEstablishARequirementAboveTheFrameworkFloor(): void
    {
        // PCI-DSS asks for twelve; the framework's own signup surface enforces
        // eight, and nothing in this process can see what an application's own
        // form validates. Reporting a pass here is the false-pass ADR-0041 exists
        // to prevent.
        $result = new PasswordPolicyCheck($this->profile(minLength: 12), constrained: true)->execute();

        self::assertSame(CheckStatus::Skip, $result->status);
        self::assertTrue(str_contains($result->message, '12'), $result->message);
        self::assertTrue(str_contains($result->message, 'requireAtLeast'), $result->message);
    }

    #[Test]
    public function isNotAFailureBecauseAFailureWouldRefuseTheBoot(): void
    {
        // Under compliance strict mode a Fail aborts the boot. Aborting every
        // PCI-DSS deployment's boot over an unobservable control would punish the
        // deployments that DO enforce twelve characters exactly as hard as the
        // ones that do not.
        $result = new PasswordPolicyCheck($this->profile(minLength: 64), constrained: true)->execute();

        self::assertNotSame(CheckStatus::Fail, $result->status);
    }

    #[Test]
    public function saysNothingWhenNoEnabledFrameworkMandatesAMinimum(): void
    {
        // The resolved profile always carries a baseline default; assessing
        // against it would attribute a limit to frameworks that never set one.
        $result = new PasswordPolicyCheck($this->profile(minLength: 12), constrained: false)->execute();

        self::assertSame(CheckStatus::Skip, $result->status);
        self::assertTrue(str_contains($result->message, 'No enabled framework'), $result->message);
    }

    #[Test]
    public function namesTheFrameworksThatImposeTheRequirement(): void
    {
        $result = new PasswordPolicyCheck($this->profile(minLength: 12), constrained: true)->execute();

        self::assertTrue(str_contains($result->message, 'pci_dss'), $result->message);
    }

    #[Test]
    public function readsTheRequirementTheResolverActuallyProduces(): void
    {
        // The point of the check: the number it reports is the one
        // ComplianceProfileResolver computed from the enabled frameworks, not a
        // literal restated here.
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([ComplianceFramework::PciDss]);
        $constraints = $resolver->constraints([ComplianceFramework::PciDss]);

        $result = new PasswordPolicyCheck($profile, $constraints->passwordMinLength)->execute();

        self::assertTrue($constraints->passwordMinLength, 'PCI-DSS constrains password length');
        self::assertGreaterThan(PasswordHasherInterface::MIN_LENGTH, $profile->passwordMinLength);
        self::assertSame(CheckStatus::Skip, $result->status);
        self::assertTrue(
            str_contains($result->message, (string) $profile->passwordMinLength),
            'the resolved requirement must appear in the message: ' . $result->message,
        );
    }

    private function profile(int $minLength): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::PciDss],
            passwordMinLength: $minLength,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 365,
            mfaRequirement: 'none',
            encryptionAtRest: false,
            encryptionInTransit: false,
            tamperEvidentAudit: false,
            explicitConsent: false,
            consentWithdrawal: false,
            individualNotification: false,
            breachRegister: false,
        );
    }
}
