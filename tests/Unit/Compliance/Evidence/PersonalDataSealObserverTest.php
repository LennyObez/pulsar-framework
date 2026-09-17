<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\PersonalDataSealObserver;
use Pulsar\Compliance\Frameworks\CcpaMapping;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Probe\CryptographicControlProbe;
use Pulsar\Compliance\Probe\DataProtectionAtRestProbe;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Tests\Support\Compliance\UnauthenticatedFieldEncryptor;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;
use SodiumException;

use function base64_encode;
use function bin2hex;
use function random_bytes;
use function str_contains;

/**
 * The deployment is handed a value classified as personal data, and the fact
 * reports what it did with it.
 *
 * WHAT THIS FILE IS FOR. GDPR Art 5(1)(f) and Art 32 are declared over PERSONAL
 * DATA, and until ADR-0066 the strongest thing the subsystem could say about that
 * estate was `extension_loaded('sodium')` — regraded to Available by ADR-0061
 * because it answers identically on a deployment that encrypts everything and one
 * that encrypts nothing — beside a resolved master key. Neither can carry a
 * control, so both articles were unsatisfiable by any deployment at all and
 * `composer compliance:check` failed on the shipped default. Demoting a false
 * green is only half a repair; the other half is measuring the thing.
 *
 * THE BROKEN ENCRYPTORS ARE THE POINT. Each is bound, constructible, and
 * indistinguishable from a sound one to every fact that reads a class name or a
 * config key — which is exactly the deployment the repair exists to separate from
 * a sound one. `EncryptorInterface` is `#[Api]` and bound BY CONTRACT, so every
 * one of them is a deployment an application can actually produce.
 *
 * Nothing here states an outcome and nothing asserts a grade table: the shapes
 * are built, the real observer runs, and what is asserted is what it concluded.
 */
#[CoversClass(PersonalDataSealObserver::class)]
#[CoversClass(CryptographicControlProbe::class)]
#[CoversClass(DataProtectionAtRestProbe::class)]
#[CoversClass(GdprMapping::class)]
#[CoversClass(CcpaMapping::class)]
final class PersonalDataSealObserverTest extends TestCase
{
    private static function observer(): PersonalDataSealObserver
    {
        return new PersonalDataSealObserver(new Randomizer(new Secure()));
    }

    /**
     * @throws SodiumException
     */
    private static function realEncryptor(): Encryptor
    {
        return Encryptor::fromMasterKey(MasterKey::fromHex(bin2hex(random_bytes(32))));
    }

    // --- The encryptor this framework ships -----------------------------------

    /**
     * The whole point, stated once: the shipped cipher yields a fact that is
     * MEASURED, present, and admissible as proof.
     *
     * @throws SodiumException
     */
    #[Test]
    public function theShippedEncryptorSealsOpensAndRefusesWhatItShould(): void
    {
        $observation = self::observer()->observe(self::realEncryptor());

        self::assertSame(ObservationId::PersonalDataFieldSealed, $observation->id);
        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertTrue(
            $observation->isAdmissibleAsProof(),
            'A personal-data field sealed and opened through the live at-rest rule is the only '
                . 'kind of fact that may carry a control about personal data.',
        );
        self::assertTrue($observation->subjectExists);
        self::assertStringContainsString('nothing was written to any store', $observation->detail);
    }

    /**
     * And it is about personal data, not about cryptography in general.
     *
     * The estate is a property of the fact's IDENTITY, which is what stops this
     * measurement from being read as an answer about health data, cardholder data
     * or the contracts and pricing that make up confidential information. Those
     * are siblings in the vocabulary and deliberately do not nest.
     */
    #[Test]
    public function theFactInterrogatesPersonalDataAndNothingWider(): void
    {
        self::assertSame(
            'personal_data',
            ObservationId::PersonalDataFieldSealed->subject()->value,
        );
    }

    // --- Absence ---------------------------------------------------------------

    /**
     * No encryptor is a fact about the deployment, and it observes ABSENT rather
     * than subjectless.
     *
     * The difference decides whether the control leaves the coverage denominator.
     * A deployment with no cipher has not escaped the question — it processes
     * personal data and writes it exactly as the application left it — so the
     * control has to fail rather than evaporate, which is the inversion that once
     * let "there is no transport to encrypt" satisfy an encryption control.
     */
    #[Test]
    public function noEncryptorIsReportedAsARunThatCouldNotHappen(): void
    {
        $observation = self::observer()->observe(null);

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
        self::assertTrue(
            $observation->subjectExists,
            'A deployment with no cipher still has personal data to protect, so the control must '
                . 'stay in the denominator.',
        );
        self::assertStringContainsString('No encryptor is in service', $observation->detail);
    }

    // --- The shapes a bound encryptor can still be wrong in ---------------------

    /**
     * Opaque, reversible, and neither authenticated nor randomised.
     *
     * This one passes the two subjects most people would think to check and fails
     * the two that matter, so the finding has to name BOTH failures rather than
     * stopping at the first.
     */
    #[Test]
    public function anUnauthenticatedEncryptorFailsOnIntegrityAndOnRepetition(): void
    {
        $observation = self::observer()->observe(new UnauthenticatedFieldEncryptor());

        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
        self::assertStringContainsString('is not authenticated', $observation->detail);
        self::assertStringContainsString('produced identical stored forms', $observation->detail);
        self::assertStringNotContainsString(
            'a personal-data field is sealed:',
            $observation->detail,
            'Concealment and recoverability both held on this deployment; reporting them as '
                . 'failures too would send an operator to the wrong place.',
        );
    }

    /**
     * The encryptor that stores the value and calls it protected.
     *
     * The classification reaches the cipher, the cipher returns the plaintext, and
     * every binding inspection in the evidence set reads clean.
     */
    #[Test]
    public function anEncryptorThatConcealsNothingIsCaught(): void
    {
        $observation = self::observer()->observe(new class implements EncryptorInterface {
            #[Override]
            public function encrypt(
                #[SensitiveParameter]
                string $plaintext,
            ): string {
                return $plaintext;
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                return $encoded;
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
            {
                return $this;
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('contain the value in the clear', $observation->detail);
    }

    /**
     * And the one a naive concealment check would pass: base64 of the plaintext.
     *
     * The stored form does not contain the value as it stands, and it does once
     * decoded. This is why the concealment subject decodes before it looks.
     */
    #[Test]
    public function base64IsNotConcealment(): void
    {
        $observation = self::observer()->observe(new class implements EncryptorInterface {
            #[Override]
            public function encrypt(
                #[SensitiveParameter]
                string $plaintext,
            ): string {
                return base64_encode($plaintext);
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                $raw = base64_decode($encoded, true);

                return $raw === false ? '' : $raw;
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
            {
                return $this;
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('contain the value in the clear', $observation->detail);
    }

    /**
     * A cipher that refuses to seal at all is a subject that RAN and failed, not a
     * run that could not happen.
     *
     * The distinction is what separates "nothing here protects personal data" from
     * "something does and it does not work", and an operator is sent to a
     * different place by each.
     */
    #[Test]
    public function anEncryptorThatThrowsIsAFailedSubjectRatherThanAnAbsentOne(): void
    {
        $observation = self::observer()->observe(new class implements EncryptorInterface {
            #[Override]
            public function encrypt(
                #[SensitiveParameter]
                string $plaintext,
            ): string {
                throw SecurityException::encryptionFailed('no key material');
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                throw SecurityException::encryptionFailed('no key material');
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
            {
                return $this;
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('bound and not usable', $observation->detail);
        self::assertStringNotContainsString('No encryptor is in service', $observation->detail);
    }

    /**
     * The measurement leaves nothing behind, and this asserts it from outside
     * rather than from the docblock: the value never reaches a store because
     * `serialize()` HANDS BACK the at-rest form.
     *
     * Checked by recording every plaintext the cipher was asked to seal and every
     * ciphertext it was asked to open, then confirming the observer only ever
     * spoke to the cipher — there is no store to inspect, which is the property
     * under test.
     *
     * @throws SodiumException
     */
    #[Test]
    public function theMeasurementOnlyEverSpeaksToTheCipher(): void
    {
        $recording = new class (self::realEncryptor()) implements EncryptorInterface {
            /** @var list<string> */
            public array $sealed = [];

            /** @var list<string> */
            public array $opened = [];

            public function __construct(private readonly EncryptorInterface $inner) {}

            #[Override]
            public function encrypt(
                #[SensitiveParameter]
                string $plaintext,
            ): string {
                $this->sealed[] = $plaintext;

                return $this->inner->encrypt($plaintext);
            }

            #[Override]
            public function decrypt(string $encoded): string
            {
                $this->opened[] = $encoded;

                return $this->inner->decrypt($encoded);
            }

            #[Override]
            public function withDerivedKey(MasterKey $masterKey, int $subKeyId, string $context): self
            {
                return $this;
            }
        };

        $observation = self::observer()->observe($recording);

        self::assertTrue($observation->present);
        self::assertCount(
            2,
            $recording->sealed,
            'The same value is sealed exactly twice: once for the stored form and once to show '
                . 'that equal values do not repeat.',
        );
        self::assertSame(
            $recording->sealed[0],
            $recording->sealed[1],
            'The repetition subject must seal the SAME value, or a difference in the stored form '
                . 'would come from the input rather than from the deployment.',
        );
        self::assertCount(
            2,
            $recording->opened,
            'One open for the read-back and one for the modified copy; anything more is the '
                . 'measurement doing work it does not report.',
        );
    }

    // --- What the fact carries, assessed through the real mappings -------------

    /**
     * The three controls declared over personal data whose probes require this
     * fact, each reaching Satisfied only because the estate was measured.
     */
    #[Test]
    public function theMeasurementCarriesTheControlsDeclaredOverPersonalData(): void
    {
        $deployment = DeploymentUnderAssessment::fullyEquipped(
            [ComplianceFramework::Gdpr, ComplianceFramework::Ccpa],
        );

        foreach (
            [
                [ComplianceFramework::Gdpr, 'Art5(1)(f)'],
                [ComplianceFramework::Gdpr, 'Art32'],
                [ComplianceFramework::Ccpa, 'CCPA-1798.150'],
            ] as [$framework, $control]
        ) {
            $finding = $deployment->finding($framework, $control);

            self::assertSame(ControlOutcome::Satisfied, $finding->outcome, $control);
            self::assertStringContainsString(
                ObservationId::PersonalDataFieldSealed->value,
                $finding->summary,
                $control,
            );
        }
    }

    /**
     * And the controls it does NOT carry, which is the half that keeps the estate
     * narrow.
     *
     * These two cite the probes this fact was added to and regulate different
     * estates — NIST CSF PR.DS confidential information, NIS2 Art 21(h) the
     * cryptographic platform. Both see the fact present and measured, and neither
     * may use it: the estate join holds it aside and the finding says so in as
     * many words. If either turns green without an observer being written for the
     * estate it names, the fact has been widened and the defect is back.
     */
    #[Test]
    public function theMeasurementDoesNotReachTheSiblingEstates(): void
    {
        $deployment = DeploymentUnderAssessment::fullyEquipped(
            [ComplianceFramework::Nis2, ComplianceFramework::NistCsf],
        );

        foreach (
            [
                [ComplianceFramework::NistCsf, 'NIST-PR.DS', 'confidential_information'],
                [ComplianceFramework::Nis2, 'NIS2-Art21(h)', 'cryptographic_platform'],
            ] as [$framework, $control, $estate]
        ) {
            $finding = $deployment->finding($framework, $control);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $control);
            self::assertStringContainsString(
                ObservationId::PersonalDataFieldSealed->value . ' was exercised and interrogated '
                    . 'personal_data',
                $finding->summary,
                $control,
            );
            self::assertStringContainsString('regulates ' . $estate, $finding->summary, $control);
        }
    }

    /**
     * The health-data estate is not reached from the other direction either: its
     * probe was never given this fact.
     *
     * {@see \Pulsar\Compliance\Probe\HealthDataProtectionProbe} is deliberately
     * untouched by ADR-0066. Adding a personal-data fact to a health-data control
     * would put a requirement in front of an operator that could never decide the
     * control and could only fail it for the wrong reason, so HIPAA's encryption
     * controls stay exactly as they were: unsatisfiable until an observer exists
     * for protected health information, and saying so.
     */
    #[Test]
    public function theHealthDataProbeWasNotGivenAPersonalDataRequirement(): void
    {
        $finding = DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::Hipaa])
            ->finding(ComplianceFramework::Hipaa, '164.312(a)(2)(iv)');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringNotContainsString(
            ObservationId::PersonalDataFieldSealed->value,
            $finding->summary,
            'A control about health data must not even be asked about a personal-data fact.',
        );
        self::assertStringContainsString('regulates health_data', $finding->summary);
    }

    /**
     * The counter-shape, assessed through the real mapping: an encryptor that
     * conceals and defends nothing leaves both GDPR articles red, on a deployment
     * where every binding inspection reads clean.
     *
     * This is the assertion that makes the two above worth having. Without it,
     * they would pass on a probe that satisfied itself the moment any encryptor
     * was bound at all.
     */
    #[Test]
    public function anUnsoundEncryptorLeavesTheArticlesRedOnAnOtherwiseCleanDeployment(): void
    {
        $deployment = DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::Gdpr])
            ->withUnauthenticatedFieldEncryption();

        foreach (['Art5(1)(f)', 'Art32'] as $control) {
            $finding = $deployment->finding(ComplianceFramework::Gdpr, $control);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $control);
            self::assertNotSame([], $finding->remediations, $control);
        }

        self::assertSame(
            ControlOutcome::Satisfied,
            $deployment->finding(ComplianceFramework::Gdpr, 'Art25')->outcome,
            'Pseudonymisation is unaffected by the encryptor being unsound, so a test that saw '
                . 'everything go red would be measuring the fixture rather than the probe.',
        );
    }

    /**
     * The probe's own remediation names the binding an operator would have to fix,
     * so a red finding on this fact is actionable rather than a complaint.
     */
    #[Test]
    public function theRemediationNamesTheContractAnApplicationWouldHaveBound(): void
    {
        $finding = DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::Gdpr])
            ->withUnauthenticatedFieldEncryption()
            ->finding(ComplianceFramework::Gdpr, 'Art32');

        $joined = '';

        foreach ($finding->remediations as $remediation) {
            $joined .= $remediation . "\n";
        }

        self::assertTrue(
            str_contains($joined, 'EncryptorInterface'),
            'A deployment whose encryptor is the problem must be told which contract to rebind.',
        );
    }
}
