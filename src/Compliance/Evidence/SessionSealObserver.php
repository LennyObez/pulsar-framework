<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Security\Session\SessionPayloadCipherInterface;
use Random\Randomizer;
use Throwable;

use function base64_decode;
use function bin2hex;
use function count;
use function hash_equals;
use function implode;
use function intdiv;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * Whether the session cipher this deployment bound actually seals a payload.
 *
 * WHY THIS EXISTS. ADR-0061 took `extension_loaded('sodium')` down from Measured
 * to {@see \Pulsar\Compliance\Control\ObservationGrade::Available}, and it was
 * right to: the reading is identical on a deployment that encrypts every field
 * and on one that encrypts nothing. But nine controls across seven frameworks
 * had been resting on it, and demoting a fact does not tell an operator anything
 * they did not already know — it only stops the report from telling them
 * something false. The fact that was supposed to carry session payloads instead,
 * `session_encryption_resolved`, is a configuration read: `SessionEncryption` was
 * constructed and bound. That is "the class is bound", which is the claim
 * ADR-0041 showed to be worthless, and the gatherer's own source admitted it
 * could not do better — the cipher is `#[Internal]` to the Security module, so
 * Compliance could not hold one, so nothing could exercise it.
 *
 * {@see SessionPayloadCipherInterface} is what closed that: the Security module
 * publishes sealing and opening, the way it already published
 * {@see \Pulsar\Security\Crypto\TokenizationServiceInterface} for the vault, and
 * this class does to the session cipher what {@see TokenVaultObserver} does to
 * the vault. Prefer measuring a thing over demoting it.
 *
 * WHAT IT RUNS, four subjects against the live cipher, which are the two words
 * GDPR Art 5(1)(f) uses — integrity and confidentiality — plus the one thing
 * neither word covers and a session cipher still has to do:
 *
 *   1. seal a synthetic payload — the sealed form must not carry the payload,
 *      as it stands or once base64-decoded
 *   2. open it under the same context — the payload must come back byte for byte
 *   3. modify one byte of the sealed form — opening it must be REFUSED
 *   4. open it as a different session — that must be refused too
 *
 * Subject 3 is authentication and subject 4 is binding, and a cipher can fail
 * either while passing subject 1. Opaque output is not a seal: an unauthenticated
 * ciphertext can be edited by whoever holds the storage, and a ciphertext that
 * ignores its session context can be lifted from one session into another. Both
 * are the transplant attack {@see \Pulsar\Security\Session\SessionEncryption}
 * names in its own class docblock, and until now nothing checked that the class
 * doing the naming still did it.
 *
 * THIS MEASUREMENT WRITES NOTHING, and the difference from {@see TokenVaultObserver}
 * is principled rather than squeamish. The vault's at-rest form lives in a store
 * the service writes THROUGH, so nothing short of a write and a read-back could
 * show what the persisted bytes look like. The session cipher RETURNS the at-rest
 * form: the bytes handed back are the bytes the handler would store. So the
 * persisted representation is inspectable without persisting it, and no session
 * file, row or cookie is created, nothing is written under a synthetic session
 * id, and there is no cleanup that can fail.
 *
 * WHAT THIS ESTABLISHES, AND WHAT IT DOES NOT. It establishes that the cipher
 * this deployment bound seals, opens, and refuses both a modified and a
 * transplanted payload. It does NOT establish that every payload the application
 * writes goes through that cipher: the write path is
 * {@see \Pulsar\Security\Session\SessionManager}, and what stands behind it here
 * is still `session_encryption_resolved`, a config read. That is the deferred
 * residue this repair leaves standing — a Declared fact still fills a required
 * slot in {@see \Pulsar\Compliance\Probe\SecureSessionProbe} without proving
 * anything — and the pairing is deliberate: the composition root binds ONE
 * cipher instance and hands that same instance to the session manager, so the
 * object measured here is the object in the write path, and what is unproven is
 * only that the manager uses it.
 *
 * THE ESTATE IS SESSION PAYLOADS AND NOTHING WIDER. The fact is
 * {@see ObservationId::SessionPayloadsSealed}, whose subject is
 * {@see \Pulsar\Compliance\Control\ControlSubject::SessionPayloads}, so it
 * carries SWIFT CSP 2.6 and does not carry GDPR Art 5(1)(f), CCPA 1798.150,
 * NIST CSF PR.DS or HIPAA's two encryption controls — those regulate personal,
 * confidential and health data, and sealing a session is not an answer about a
 * database full of records. Naming the estate one level wider would make it one,
 * and that is `extension_loaded('sodium')` with a longer name.
 *
 * THREE OF THOSE FIVE HAVE SINCE BEEN CLOSED, and how they were closed is what
 * this paragraph is really for. It used to end "those five controls still cannot
 * reach Satisfied on any deployment this release can build. Measuring their
 * estates is the next observer, not this one." That is what happened:
 * {@see PersonalDataSealObserver} measured the personal-data estate (ADR-0066)
 * and GDPR Art 5(1)(f), GDPR Art 32 and CCPA 1798.150 went green on their own
 * fact. The estate here did not move a millimetre, and this fact still carries
 * exactly one control. NIST CSF PR.DS and HIPAA's two encryption controls remain
 * unsatisfiable on every deployment this release can build, and the remedy is the
 * same again: an observer for `confidential_information` and one for
 * `health_data`, never a wider name for this one.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SessionSealObserver
{
    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live session cipher';

    /**
     * The handler and domain the synthetic context is built from.
     *
     * Deliberately not a handler this deployment uses. They travel into the
     * cipher's context binding and nowhere else — nothing is written through a
     * handler here — so what they have to be is unmistakably the probe's, in case
     * a custom implementation ever logs the context it was asked to seal for.
     */
    private const string PROBE_HANDLER = 'compliance.seal_probe';

    private const string PROBE_DOMAIN = 'compliance.seal_probe.invalid';

    /**
     * Prefix of the synthetic session id, so a session id this check invents can
     * never be mistaken for one a user holds — in a log line a custom
     * implementation writes, or by a reader of this file.
     */
    private const string PROBE_SESSION_PREFIX = 'compliance.seal_probe.';

    /** Suffix that turns the probe's session id into a demonstrably different one. */
    private const string FOREIGN_SUFFIX = '.foreign';

    /** Bytes of randomness in the synthetic payload; 16 bytes = 32 hex characters. */
    private const int PROBE_PAYLOAD_BYTES = 16;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * Exercise the cipher, or report that there was none to exercise.
     *
     * ABSENCE IS REPORTED ABSENT, NOT SUBJECTLESS, and the distinction is the one
     * {@see DatabaseTlsObserver} got wrong in the dangerous direction. A
     * deployment with no session cipher has not escaped the question: it has
     * session payloads and they are written exactly as the application left them.
     * {@see Observation::noSubject()} would take the control out of the coverage
     * denominator and off the assessor's page, which is how "there is no
     * transport to encrypt" came to satisfy an encryption control. So the run is
     * reported as one that could not happen, which observes absent, and the
     * control fails.
     *
     * @param SessionPayloadCipherInterface|null $cipher The cipher the composition
     *        root resolved — the same instance it hands to the session manager.
     *        Null when session encryption is off, or when the master key it
     *        derives from never loaded, which is a fact and not a pass
     */
    #[NoDiscard]
    public function observe(?SessionPayloadCipherInterface $cipher): Observation
    {
        if ($cipher === null) {
            return Observation::measured(
                ObservationId::SessionPayloadsSealed,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No session cipher is in service, so nothing was sealed and session payloads '
                        . 'reach the storage handler exactly as the application left them. Either '
                        . 'security.session.encryption is false in config/security.php, or the '
                        . 'master key the cipher derives from never loaded.',
                ),
                self::class,
            );
        }

        return Observation::measured(ObservationId::SessionPayloadsSealed, $this->exercise($cipher), self::class);
    }

    /**
     * Run the subjects against the cipher.
     *
     * A throw from the seal itself is reported as a subject that RAN and failed,
     * not as a run that could not happen: the cipher was called and it refused.
     * Those are different findings — one says there is no cryptography here, the
     * other says there is and it does not work — and an operator is sent to a
     * different place by each.
     */
    private function exercise(SessionPayloadCipherInterface $cipher): Measurement
    {
        $payload = bin2hex($this->randomizer->getBytes(self::PROBE_PAYLOAD_BYTES));
        $session = self::PROBE_SESSION_PREFIX . bin2hex($this->randomizer->getBytes(8));

        try {
            $sealed = $cipher->encrypt($payload, $session, self::PROBE_HANDLER, self::PROBE_DOMAIN);
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'seal a payload',
                    sprintf(
                        'The session cipher refused to seal a synthetic payload: %s. Nothing this '
                            . 'deployment writes to a session is sealed by it.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'A session cipher is bound but not usable: sealing failed with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $results = [
            self::sealConceals($sealed, $payload),
            self::payloadRecoverable($cipher, $sealed, $payload, $session),
            self::modifiedSealRefused($cipher, $sealed, $session),
            self::foreignSessionRefused($cipher, $sealed, $session),
        ];

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * The sealed form must not carry the payload it stands for.
     *
     * Checked against the base64-decoded bytes as well as the string itself, and
     * the second check is not padding: a cipher that base64-encoded the plaintext
     * and returned it would pass a naive `str_contains` while storing the session
     * in the clear. Decoding is attempted rather than assumed — an implementation
     * is free to return any encoding — and a form that does not decode is judged
     * on its own bytes alone.
     */
    private static function sealConceals(string $sealed, string $payload): ExecutedSubject
    {
        $decoded = base64_decode($sealed, true);
        $carriesPayload = str_contains($sealed, $payload)
            || ($decoded !== false && str_contains($decoded, $payload));

        return $carriesPayload
            ? ExecutedSubject::failed(
                'the sealed form conceals the payload',
                'The bytes the cipher returned contain the session payload in the clear, so what '
                    . 'the handler stores is readable by whoever can read the storage.',
            )
            : ExecutedSubject::passed(
                'the sealed form conceals the payload',
                sprintf(
                    'The sealed form is %d bytes and carries none of the payload it stands for, '
                        . 'encoded or decoded.',
                    strlen($sealed),
                ),
            );
    }

    /**
     * A cipher that cannot give the payload back has not sealed the session; it
     * has ended it. Every request would arrive at an empty session and the
     * deployment would log its users out rather than protect them.
     */
    private static function payloadRecoverable(
        SessionPayloadCipherInterface $cipher,
        string $sealed,
        string $payload,
        string $session,
    ): ExecutedSubject {
        try {
            $recovered = $cipher->decrypt($sealed, $session, self::PROBE_HANDLER, self::PROBE_DOMAIN);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the payload is recoverable',
                sprintf(
                    'Opening the payload the cipher had just sealed failed: %s.',
                    $failure->getMessage(),
                ),
            );
        }

        return hash_equals($payload, $recovered)
            ? ExecutedSubject::passed(
                'the payload is recoverable',
                'The cipher returned the payload byte for byte, so a sealed session can still be '
                    . 'read back by the deployment that sealed it.',
            )
            : ExecutedSubject::failed(
                'the payload is recoverable',
                'The cipher returned something other than the payload it was given, so a session '
                    . 'sealed by this deployment cannot be resolved back to the state it held.',
            );
    }

    /**
     * One modified byte must make the sealed form unopenable.
     *
     * This is the integrity half, and it is the half opaque output does not
     * provide. The byte is changed three quarters of the way in, past any key
     * identifier or nonce header an implementation carries at the front, so what
     * is modified is the sealed payload itself rather than the envelope around
     * it — a refusal that came only from an unrecognised key id would prove
     * something much weaker than this subject claims.
     *
     * ANY return at all is a failure, including a return of something other than
     * the payload. An authenticated cipher does not hand back a value it could
     * not verify; one that hands back plausible bytes for modified input lets
     * whoever can write the session storage choose what the application reads.
     */
    private static function modifiedSealRefused(
        SessionPayloadCipherInterface $cipher,
        string $sealed,
        string $session,
    ): ExecutedSubject {
        $length = strlen($sealed);

        if ($length === 0) {
            return ExecutedSubject::failed(
                'a modified seal is refused',
                'The cipher returned an empty sealed form, so there is nothing to modify and '
                    . 'nothing was sealed.',
            );
        }

        // Always in range: intdiv(3L, 4) is at most L - 1 for every L >= 1, and L
        // is not zero here.
        $index = intdiv($length * 3, 4);
        $modified = $sealed;
        $modified[$index] = $sealed[$index] === 'A' ? 'B' : 'A';

        try {
            $opened = $cipher->decrypt($modified, $session, self::PROBE_HANDLER, self::PROBE_DOMAIN);
        } catch (Throwable) {
            return ExecutedSubject::passed(
                'a modified seal is refused',
                'One modified byte in the sealed form was refused, so the bytes at rest are '
                    . 'authenticated and cannot be edited by whoever can reach the storage.',
            );
        }

        return ExecutedSubject::failed(
            'a modified seal is refused',
            sprintf(
                'The cipher opened a sealed form with one byte changed and returned %d bytes, so '
                    . 'the session bytes at rest are not authenticated: whoever can write the '
                    . 'storage can change what the application reads back.',
                strlen($opened),
            ),
        );
    }

    /**
     * A payload sealed for one session must not open as another.
     *
     * The binding half. Confidentiality and authentication together still allow a
     * transplant: lift the sealed bytes out of one session's storage, drop them
     * into another's, and an unbound cipher opens them happily — every field the
     * application trusts in the session, an authenticated user id among them,
     * arrives under someone else's session id.
     *
     * The foreign context is the probe's own session id with a suffix, so it
     * differs by construction rather than by chance, and it can collide with no
     * real session.
     */
    private static function foreignSessionRefused(
        SessionPayloadCipherInterface $cipher,
        string $sealed,
        string $session,
    ): ExecutedSubject {
        try {
            $opened = $cipher->decrypt(
                $sealed,
                $session . self::FOREIGN_SUFFIX,
                self::PROBE_HANDLER,
                self::PROBE_DOMAIN,
            );
        } catch (Throwable) {
            return ExecutedSubject::passed(
                'a seal from another session is refused',
                'A payload sealed for one session was refused when offered as another, so the '
                    . 'sealed form is bound to its session and cannot be transplanted.',
            );
        }

        return ExecutedSubject::failed(
            'a seal from another session is refused',
            sprintf(
                'The cipher opened a payload sealed for a different session and returned %d '
                    . 'bytes, so sealed session state can be lifted from one session into another.',
                strlen($opened),
            ),
        );
    }

    /**
     * The sentence the report prints, naming what failed when something did.
     *
     * @param list<ExecutedSubject> $results
     *
     * @return non-empty-string
     */
    private static function describe(array $results): string
    {
        $failed = [];

        foreach ($results as $result) {
            if (!$result->passed) {
                $failed[] = $result->name . ': ' . $result->detail;
            }
        }

        return $failed === []
            ? sprintf(
                'A synthetic payload was sealed by the live session cipher and opened again: the '
                    . 'sealed form concealed it, the payload came back byte for byte, one modified '
                    . 'byte was refused, and the same bytes offered as another session were '
                    . 'refused. %d subject(s) ran, and nothing was written to any session store.',
                count($results),
            )
            : 'The live session cipher did not seal a session payload — ' . implode(' | ', $failed);
    }
}
