<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\SessionSealObserver;
use Pulsar\Compliance\Frameworks\SwiftCspMapping;
use Pulsar\Compliance\Probe\SecureSessionProbe;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Security\Session\SessionPayloadCipherInterface;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;
use Random\Engine\Secure;
use Random\Randomizer;
use SodiumException;

use function array_filter;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function explode;
use function random_bytes;
use function str_contains;
use function strrev;

/**
 * The session cipher is put to work, and the fact reports what came back.
 *
 * WHAT THIS FILE IS FOR. Before it, the strongest thing the subsystem could say
 * about session payloads at rest was that a class had been constructed —
 * `session_encryption_resolved`, grade Declared — with
 * `extension_loaded('sodium')` standing beside it, which ADR-0061 demoted to
 * Available for answering identically on a deployment that encrypts everything
 * and one that encrypts nothing. Neither can carry a control, so SWIFT CSP 2.6
 * was unsatisfiable by any deployment at all. Demoting a false green is only
 * half a repair; the other half is measuring the thing.
 *
 * Every assertion below therefore runs the REAL cipher or a deliberately broken
 * one, through the real observer. Nothing here states an outcome, and nothing
 * asserts a grade table: the shapes are built, the observer runs, and what is
 * asserted is what it concluded.
 *
 * THE THREE BROKEN CIPHERS ARE THE POINT. Each is bound, constructible, and
 * indistinguishable from a working one to every fact that reads a class name or
 * a config key — which is exactly the deployment the whole repair exists to
 * separate from a sound one.
 */
#[CoversClass(SessionSealObserver::class)]
#[CoversClass(SessionEncryption::class)]
#[CoversClass(SecureSessionProbe::class)]
#[CoversClass(SwiftCspMapping::class)]
final class SessionSealObserverTest extends TestCase
{
    private static function observer(): SessionSealObserver
    {
        return new SessionSealObserver(new Randomizer(new Secure()));
    }

    /**
     * @throws SodiumException
     */
    private static function realCipher(): SessionEncryption
    {
        return SessionEncryption::fromMasterKey(MasterKey::fromHex(bin2hex(random_bytes(32))));
    }

    // --- The cipher this framework ships --------------------------------------

    /**
     * The whole point, stated once: a working cipher yields a fact that is
     * MEASURED, present, and admissible as proof.
     *
     * @throws SodiumException
     */
    #[Test]
    public function theShippedCipherSealsOpensAndRefusesWhatItShould(): void
    {
        $observation = self::observer()->observe(self::realCipher());

        self::assertSame(ObservationId::SessionPayloadsSealed, $observation->id);
        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertTrue(
            $observation->isAdmissibleAsProof(),
            'A payload sealed and opened through the live cipher is the only kind of fact that '
                . 'may carry a control about session payloads.',
        );
        self::assertTrue($observation->subjectExists);
        self::assertStringContainsString('nothing was written to any session store', $observation->detail);
    }

    /**
     * And it is about session payloads, not about cryptography in general.
     *
     * The estate is a property of the fact's identity, so this is what stops the
     * measurement from being read as an answer about personal data — the defect
     * `extension_loaded('sodium')` had in its second half.
     */
    #[Test]
    public function theFactInterrogatesSessionPayloadsAndNothingWider(): void
    {
        self::assertSame(
            'session_payloads',
            ObservationId::SessionPayloadsSealed->subject()->value,
        );
    }

    // --- Absence ---------------------------------------------------------------

    /**
     * No cipher is ABSENT, never subjectless.
     *
     * A deployment with no session cipher has not escaped the question: it has
     * session payloads and they reach the handler as the application left them.
     * Answering {@see \Pulsar\Compliance\Control\Observation::noSubject()} would
     * take the control out of the coverage denominator, which is how "there is no
     * transport to encrypt" once satisfied an encryption control.
     */
    #[Test]
    public function noCipherIsReportedAbsentAndStillHasASubject(): void
    {
        $observation = self::observer()->observe(null);

        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
        self::assertTrue(
            $observation->subjectExists,
            'Session payloads exist whether or not something seals them; a deployment that '
                . 'seals nothing must fail this control rather than leave the denominator.',
        );
        self::assertStringContainsString('exactly as the application left them', $observation->detail);
    }

    /**
     * A cipher that throws on the first seal is a subject that RAN and failed,
     * not a run that could not happen. "There is no cryptography here" and "there
     * is and it does not work" send an operator to different places.
     */
    #[Test]
    public function aCipherThatRefusesToSealIsAFailureAndNotAnAbsentRun(): void
    {
        $observation = self::observer()->observe(new class implements SessionPayloadCipherInterface {
            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                throw SecurityException::sessionEncryptionFailed('no key material');
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                throw SecurityException::sessionEncryptionFailed('no key material');
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('bound but not usable', $observation->detail);
        self::assertStringContainsString('no key material', $observation->detail);
    }

    // --- Three ciphers that are bound and seal nothing --------------------------

    /**
     * Base64 is not encryption, and `str_contains($sealed, $payload)` alone would
     * not have caught it — which is why the sealed form is checked decoded too.
     */
    #[Test]
    public function aCipherThatMerelyEncodesIsCaughtByDecodingItsOutput(): void
    {
        $observation = self::observer()->observe(new class implements SessionPayloadCipherInterface {
            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                return base64_encode($data);
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                return (string) base64_decode($encrypted, true);
            }
        });

        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
        self::assertStringContainsString('the sealed form conceals the payload', $observation->detail);
        self::assertStringContainsString('in the clear', $observation->detail);
    }

    /**
     * Opaque output is not a seal either. This cipher hides the payload and
     * authenticates nothing, so whoever can write the session store chooses what
     * the application reads back.
     */
    #[Test]
    public function anUnauthenticatedCipherIsCaughtByModifyingOneByte(): void
    {
        $observation = self::observer()->observe(new class implements SessionPayloadCipherInterface {
            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                return base64_encode(strrev($data)) . '.' . base64_encode($sessionId);
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                [$body] = explode('.', $encrypted, 2);

                return strrev((string) base64_decode($body, true));
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('a modified seal is refused', $observation->detail);
        self::assertStringContainsString('not authenticated', $observation->detail);
    }

    /**
     * And a cipher that authenticates its bytes while ignoring its context lets
     * the sealed state of one session be lifted into another — the transplant
     * `SessionEncryption` names in its own docblock, which nothing checked until
     * this subject existed.
     *
     * Built on the real cipher so that only ONE property is broken: the context
     * is replaced by a constant, so the AEAD tag is genuine and every other
     * subject passes.
     *
     * @throws SodiumException
     */
    #[Test]
    public function aCipherThatIgnoresItsSessionContextIsCaughtByTheTransplant(): void
    {
        $observation = self::observer()->observe(new class (self::realCipher()) implements SessionPayloadCipherInterface {
            public function __construct(private readonly SessionEncryption $inner) {}

            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                return $this->inner->encrypt($data, 'fixed', 'fixed', 'fixed');
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                return $this->inner->decrypt($encrypted, 'fixed', 'fixed', 'fixed');
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('a seal from another session is refused', $observation->detail);
        self::assertStringContainsString('lifted from one session into another', $observation->detail);
    }

    /**
     * A cipher that seals and cannot open again has ended the session rather than
     * protected it: every request would arrive at empty state.
     *
     * @throws SodiumException
     */
    #[Test]
    public function aCipherThatCannotGiveThePayloadBackFailsRecoverability(): void
    {
        $observation = self::observer()->observe(new class (self::realCipher()) implements SessionPayloadCipherInterface {
            public function __construct(private readonly SessionEncryption $inner) {}

            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                return $this->inner->encrypt($data, $sessionId, $handlerType, $domain);
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                throw SecurityException::sessionEncryptionFailed('unknown key identifier');
            }
        });

        self::assertFalse($observation->present);
        self::assertStringContainsString('the payload is recoverable', $observation->detail);
        self::assertStringContainsString('unknown key identifier', $observation->detail);
    }

    // --- What the measurement must never do -------------------------------------

    /**
     * The synthetic payload never appears in the report.
     *
     * It is 32 random hex characters and it is worth nothing, but a measurement
     * that printed the value it sealed would be a template for one that prints a
     * real payload the day the subject changes.
     *
     * @throws SodiumException
     */
    #[Test]
    public function theSyntheticPayloadIsNotPrintedInTheEvidence(): void
    {
        $cipher = new class (self::realCipher()) implements SessionPayloadCipherInterface {
            public string $seen = '';

            public function __construct(private readonly SessionEncryption $inner) {}

            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                $this->seen = $data;

                return $this->inner->encrypt($data, $sessionId, $handlerType, $domain);
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                return $this->inner->decrypt($encrypted, $sessionId, $handlerType, $domain);
            }
        };

        $observation = self::observer()->observe($cipher);

        self::assertNotSame('', $cipher->seen, 'The observer must actually have sealed something.');
        self::assertFalse(
            str_contains($observation->detail, $cipher->seen),
            'The evidence line must describe what happened, never reproduce the payload.',
        );
    }

    /**
     * Two runs seal different payloads under different session ids.
     *
     * A fixed payload would let a cipher special-case it, and a fixed session id
     * could collide with a real one. Both are caught here rather than left to the
     * reader of the observer.
     *
     * @throws SodiumException
     */
    #[Test]
    public function eachRunUsesFreshSyntheticMaterial(): void
    {
        $recorder = new class (self::realCipher()) implements SessionPayloadCipherInterface {
            /** @var list<array{string, string}> */
            public array $calls = [];

            public function __construct(private readonly SessionEncryption $inner) {}

            #[Override]
            public function encrypt(string $data, string $sessionId, string $handlerType, string $domain): string
            {
                $this->calls[] = [$data, $sessionId];

                return $this->inner->encrypt($data, $sessionId, $handlerType, $domain);
            }

            #[Override]
            public function decrypt(string $encrypted, string $sessionId, string $handlerType, string $domain): string
            {
                return $this->inner->decrypt($encrypted, $sessionId, $handlerType, $domain);
            }
        };

        $observer = self::observer();
        // Cast away: what is under test is the material each run generates, which
        // the recorder captured, not what the observer concluded about it.
        (void) $observer->observe($recorder);
        (void) $observer->observe($recorder);

        self::assertCount(2, $recorder->calls);
        self::assertNotSame($recorder->calls[0][0], $recorder->calls[1][0]);
        self::assertNotSame($recorder->calls[0][1], $recorder->calls[1][1]);
    }

    // --- Reachability: the control the measurement carries ----------------------

    /**
     * SWIFT CSP 2.6 is SATISFIED on a deployment whose session cipher works, and
     * the fact that carried it was measured on the estate the control regulates.
     *
     * The control could not reach this outcome on any deployment shape before:
     * `session_encryption_resolved` is a config read and a config read cannot
     * carry anything. A control stuck at Unsatisfied is as broken an instrument
     * as one stuck at Satisfied, so this direction is proved by execution rather
     * than argued.
     */
    #[Test]
    public function swiftOperatorSessionConfidentialityIsSatisfiedWhenTheCipherSeals(): void
    {
        $finding = DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::SwiftCsp])
            ->finding(ComplianceFramework::SwiftCsp, 'SWIFT-2.6');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome, $finding->summary);

        $carried = array_filter(
            $finding->evidence,
            static fn(Observation $observation): bool => $observation->id === ObservationId::SessionPayloadsSealed
                && $observation->isAdmissibleAsProof(),
        );

        self::assertNotSame([], $carried, 'The seal measurement must be what carried it.');
    }

    /**
     * And it FAILS on a deployment with no session cipher, rather than leaving
     * the coverage denominator.
     */
    #[Test]
    public function swiftOperatorSessionConfidentialityFailsWhenNothingSeals(): void
    {
        $finding = DeploymentUnderAssessment::withNothing([ComplianceFramework::SwiftCsp])
            ->finding(ComplianceFramework::SwiftCsp, 'SWIFT-2.6');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertNotSame([], $finding->remediations);
    }

    /**
     * THE DEPLOYMENT THE CONFIGURATION READ CANNOT FAULT. A cipher is bound, the
     * container built it, `session_encryption_resolved` reads present — and what
     * it writes is the payload base64-encoded.
     *
     * This is the session-payload analogue of the vault ADR-0041 found bound over
     * a database with no table in it, and it is the shape that makes the
     * measurement worth its cost: every fact that reads a class name or a config
     * key reports this deployment exactly as it reports a sound one.
     */
    #[Test]
    public function aBoundCipherThatSealsNothingFailsTheControlItsBindingWouldHavePassed(): void
    {
        $deployment = DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::SwiftCsp])
            ->withCiphertextThatIsNotSealed();

        $binding = $deployment->evidence()->observation(ObservationId::SessionEncryptionResolved);

        self::assertTrue(
            $binding->present,
            'The point of this shape is that the configuration read cannot tell it from a sound '
                . 'deployment; if the binding fact fails here, the shape is not the one under test.',
        );

        $finding = $deployment->finding(ComplianceFramework::SwiftCsp, 'SWIFT-2.6');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('the sealed form conceals the payload', $finding->summary);
    }
}
