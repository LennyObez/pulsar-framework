<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Random\Randomizer;
use Throwable;

use function base64_decode;
use function bin2hex;
use function count;
use function hash_equals;
use function implode;
use function in_array;
use function intdiv;
use function is_string;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * What this deployment does with a value it has been told is personal data.
 *
 * WHY THIS EXISTS. {@see SessionSealObserver} closed the session estate and used
 * to end its own docblock with the debt it left: GDPR Art 5(1)(f), CCPA 1798.150,
 * NIST CSF PR.DS and HIPAA's two encryption controls "still cannot reach Satisfied
 * on any deployment this release can build. Measuring their estates is the next
 * observer, not this one." This is that observer, for the personal-data estate,
 * and it closes GDPR Art 5(1)(f) and Art 32 — the two controls
 * `composer compliance:check` was failing on when ADR-0061 regraded
 * `extension_loaded('sodium')` to
 * {@see \Pulsar\Compliance\Control\ObservationGrade::Available} and ADR-0062 then
 * required a fact to be about the control's own estate.
 *
 * THE PROBLEM THAT HAD TO BE SOLVED FIRST, because the obvious repair is the
 * wrong one. Handing a payload to {@see EncryptorInterface} and reading it back
 * measures the default encryption subkey, and the honest estate of that fact is
 * {@see \Pulsar\Compliance\Control\ControlSubject::KeyHierarchy} — where
 * {@see ObservationId::KeyDerivationVerified} already sits. Calling such a fact
 * "personal data" because the bytes handed in were shaped like an email address
 * is naming an estate by its input, which is `extension_loaded('sodium')` with a
 * longer name and is the defect ADR-0062 exists to refuse. Pulsar does not own
 * the store the application keeps its personal data in and never will, so no
 * observer here can watch that data being written.
 *
 * WHAT PULSAR DOES OWN is the RULE that decides when a value is sealed at rest.
 * {@see ClassifiedContext} tags each field with a
 * {@see ClassificationLevel} and seals exactly the `Restricted` and `Pii` ones
 * through the encryptor the composition root bound;
 * {@see \Pulsar\Workflow\Internal\Storage\DatabaseWorkflowStorage} writes the
 * result and reads it back through the same rule. So the measurement here is not
 * "encrypt something and see if it comes back". It is: TELL THIS DEPLOYMENT A
 * VALUE IS PERSONAL DATA, AND OBSERVE WHAT IT STORES. The classification is the
 * input, the deployment's response is the fact, and that is why
 * {@see ObservationId::PersonalDataFieldSealed} interrogates
 * {@see \Pulsar\Compliance\Control\ControlSubject::PersonalData} rather than the
 * key hierarchy.
 *
 * WHAT IT RUNS, four subjects, which are the two words GDPR Art 5(1)(f) uses —
 * integrity and confidentiality — plus the property a database of personal data
 * needs and neither word names:
 *
 *   1. the classification is honoured and the stored form conceals the value —
 *      the field must be recorded as encrypted AND the bytes must not carry the
 *      value, as they stand or once base64-decoded
 *   2. the value comes back byte for byte, because a deployment that cannot read
 *      its own personal data back has destroyed it rather than protected it, and
 *      Art 5(1)(f) names accidental destruction in the same breath as disclosure
 *   3. one modified byte in the stored form is REFUSED — the integrity half, and
 *      the half opaque output does not provide: whoever can write the row picks
 *      what the application reads back otherwise
 *   4. the same personal-data value sealed twice does not produce the same stored
 *      form, so equal values cannot be matched up across rows by anyone holding
 *      the storage. A deterministic cipher passes 1, 2 and 3 and still discloses
 *      which data subjects share a postcode, a diagnosis or an employer
 *
 * THIS MEASUREMENT WRITES NOTHING, for the reason {@see SessionSealObserver}
 * writes nothing: `serialize()` RETURNS the at-rest form, so the bytes
 * `DatabaseWorkflowStorage` would put in the row are inspectable without a row
 * existing. No workflow is started, no table is touched, and there is no cleanup
 * that can fail.
 *
 * WHAT IT ESTABLISHES, AND WHAT IT DOES NOT. It establishes that when this
 * deployment is handed a value classified as personal data, the value it stores
 * conceals it, gives it back intact, refuses a modified copy and does not repeat
 * itself. It does NOT establish that the application classifies its own personal
 * data correctly, or that the personal data it holds goes anywhere near this
 * path — that is the same residual {@see TokenVaultObserver} carries for PCI Req
 * 3.4 and {@see PseudonymizationObserver} carries for Art 25, it is outside what
 * any framework can observe, and the control's requirement text carries it where
 * a reader of the report will see it.
 *
 * @see docs/adr/0066-personal-data-is-measured-by-classifying-something.md
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PersonalDataSealObserver
{
    /**
     * The field name the synthetic value is classified under.
     *
     * Private, because nothing outside this class can ever see it: the
     * classification travels no further than {@see ClassifiedContext}, which hands
     * the encryptor the VALUE and not the key, and nothing here is stored. The one
     * place it surfaces is a failure message — `ClassifiedContext` names the field
     * it could not seal, and this observer prints that message verbatim — which is
     * why the name is unmistakably the probe's rather than one an application
     * might also have chosen.
     */
    private const string PROBE_FIELD = 'compliance.personal_data_seal_probe';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the at-rest protection this deployment applies to a field classified as personal data';

    /** Bytes of randomness in the synthetic value; 16 bytes = 32 hex characters. */
    private const int PROBE_VALUE_BYTES = 16;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * Exercise the deployment's at-rest protection, or report that there is none
     * to exercise.
     *
     * ABSENCE IS REPORTED ABSENT, NOT SUBJECTLESS. A deployment with no encryptor
     * has not escaped the question: it processes personal data and writes it
     * exactly as the application left it. {@see Observation::noSubject()} would
     * take the control out of the coverage denominator and off the assessor's
     * page, which is how "there is no transport to encrypt" once came to satisfy
     * an encryption control. So this reports a run that could not happen, which
     * observes absent, and the control fails.
     *
     * @param EncryptorInterface|null $encryptor The encryptor the composition root
     *        bound — the same instance every subsystem that seals a classified
     *        field at rest is handed. Null when `PULSAR_MASTER_KEY` never loaded,
     *        which is a fact and not a pass
     */
    #[NoDiscard]
    public function observe(?EncryptorInterface $encryptor): Observation
    {
        if ($encryptor === null) {
            return Observation::measured(
                ObservationId::PersonalDataFieldSealed,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No encryptor is in service, so a field this deployment classifies as personal '
                        . 'data is written exactly as the application left it. PULSAR_MASTER_KEY '
                        . 'never loaded, and every subsystem that seals a classified value at rest '
                        . 'was built without a cipher to seal it with.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::PersonalDataFieldSealed,
            $this->exercise($encryptor),
            self::class,
        );
    }

    /**
     * Run the subjects against the deployment's own at-rest rule.
     *
     * A throw from the seal itself is reported as a subject that RAN and failed,
     * not as a run that could not happen: the deployment was asked to store a
     * personal-data field and it refused. Those are different findings — one says
     * nothing here protects personal data, the other says something does and it
     * does not work — and an operator is sent to a different place by each.
     */
    private function exercise(EncryptorInterface $encryptor): Measurement
    {
        $value = bin2hex($this->randomizer->getBytes(self::PROBE_VALUE_BYTES));
        $context = new ClassifiedContext()->set(self::PROBE_FIELD, $value, ClassificationLevel::Pii);

        try {
            $stored = $context->serialize($encryptor);
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a personal-data field is sealed',
                    sprintf(
                        'This deployment refused to store a field classified as personal data: %s. '
                            . 'Nothing it classifies that way can be written at all.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'An encryptor is bound and not usable: sealing a personal-data field failed '
                        . 'with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $sealed = self::storedForm($stored);

        $results = [
            self::classificationHonoured($stored, $sealed, $value),
            self::valueRecoverable($stored, $encryptor, $value),
            self::modifiedSealRefused($encryptor, $sealed),
            self::equalValuesDoNotSealAlike($context, $encryptor, $sealed),
        ];

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * The bytes this deployment stored for the probe field, when they are bytes at
     * all.
     *
     * Null means the field is missing from what would be written, or the
     * deployment stored something that is not a string — neither is anything the
     * subjects below can modify, decode or compare. It deliberately does NOT mean
     * "sealed": a deployment that ignored the classification and wrote the value
     * verbatim returns a perfectly good string here, and it is
     * {@see classificationHonoured()} that catches it, by reading the bookkeeping
     * beside the value and then the value itself.
     *
     * @param array{values: array<string, mixed>, classifications: array<string, string>, encrypted: list<string>} $stored
     */
    private static function storedForm(array $stored): ?string
    {
        $values = $stored['values'];

        return isset($values[self::PROBE_FIELD]) && is_string($values[self::PROBE_FIELD])
            ? $values[self::PROBE_FIELD]
            : null;
    }

    /**
     * The classification must reach the cipher, and what is stored must not carry
     * the value.
     *
     * BOTH HALVES, in one subject, because either alone is satisfied by something
     * that protects nothing. A deployment that recorded the field as encrypted and
     * stored the value verbatim would pass a bookkeeping check; a deployment that
     * stored something opaque without ever consulting the classification would
     * pass a concealment check and seal nothing the next time the rule was asked.
     *
     * The concealment half is checked against the base64-decoded bytes as well as
     * the string, and that is not padding: an encryptor that base64-encoded its
     * input and returned it would pass a naive `str_contains` while writing the
     * personal data to the row in the clear.
     *
     * @param array{values: array<string, mixed>, classifications: array<string, string>, encrypted: list<string>} $stored
     */
    private static function classificationHonoured(array $stored, ?string $sealed, string $value): ExecutedSubject
    {
        if (!in_array(self::PROBE_FIELD, $stored['encrypted'], true)) {
            return ExecutedSubject::failed(
                'a personal-data field is sealed',
                'This deployment stored a field classified as personal data without sealing it, '
                    . 'so what reaches the row is whatever the application put in the field.',
            );
        }

        if ($sealed === null) {
            return ExecutedSubject::failed(
                'a personal-data field is sealed',
                'This deployment recorded the personal-data field as sealed and stored something '
                    . 'that is not a sealed form, so nothing can be read back from the row.',
            );
        }

        $decoded = base64_decode($sealed, true);
        $carriesValue = str_contains($sealed, $value)
            || ($decoded !== false && str_contains($decoded, $value));

        return $carriesValue
            ? ExecutedSubject::failed(
                'a personal-data field is sealed',
                'The bytes this deployment stores for a personal-data field contain the value in '
                    . 'the clear, so the row is readable by whoever can read the storage.',
            )
            : ExecutedSubject::passed(
                'a personal-data field is sealed',
                sprintf(
                    'The classification reached the cipher: the stored form is %d bytes and '
                        . 'carries none of the value it stands for, encoded or decoded.',
                    strlen($sealed),
                ),
            );
    }

    /**
     * Personal data that cannot be read back has been destroyed, not protected.
     *
     * Art 5(1)(f) names accidental loss and destruction in the same sentence as
     * unauthorised disclosure, and this is the subject that separates them: a
     * deployment whose stored form does not open again has lost every record it
     * ever wrote, and would report perfect confidentiality while doing it.
     *
     * @param array{values: array<string, mixed>, classifications: array<string, string>, encrypted: list<string>} $stored
     */
    private static function valueRecoverable(
        array $stored,
        EncryptorInterface $encryptor,
        string $value,
    ): ExecutedSubject {
        try {
            $recovered = ClassifiedContext::fromSerialized($stored, $encryptor)->get(self::PROBE_FIELD);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the personal data is recoverable',
                sprintf(
                    'Reading back the personal-data field this deployment had just sealed failed: '
                        . '%s.',
                    $failure->getMessage(),
                ),
            );
        }

        if (!is_string($recovered)) {
            return ExecutedSubject::failed(
                'the personal data is recoverable',
                'Reading the sealed personal-data field back returned something other than the '
                    . 'value that was stored, so a record written by this deployment cannot be '
                    . 'resolved to what it held.',
            );
        }

        return hash_equals($value, $recovered)
            ? ExecutedSubject::passed(
                'the personal data is recoverable',
                'The sealed personal-data field opened to the value byte for byte, so a record '
                    . 'this deployment writes can still be read by the deployment that wrote it.',
            )
            : ExecutedSubject::failed(
                'the personal data is recoverable',
                'The sealed personal-data field opened to something other than the value that was '
                    . 'stored, so the records this deployment writes do not survive being read.',
            );
    }

    /**
     * One modified byte in the row must make the stored form unreadable.
     *
     * The integrity half, and the half opaque output does not provide. An
     * unauthenticated ciphertext can be edited by whoever holds the storage, and a
     * store that hands back plausible bytes for modified input lets that person
     * choose what the application believes about a data subject. ANY return at all
     * is a failure, including a return of something other than the value: an
     * authenticated cipher does not hand back a value it could not verify.
     *
     * THIS ONE SUBJECT ASKS THE CIPHER DIRECTLY, and the other three go through
     * the read path, which looks inconsistent until you follow what the read path
     * would prove. {@see ClassifiedContext::fromSerialized()} json_decodes what
     * the cipher returns, so a stream cipher with no authentication tag — where
     * one modified byte of ciphertext flips exactly one byte of plaintext — fails
     * to produce valid JSON MOST of the time and is refused for the wrong reason.
     * Reporting that as "the personal data at rest is authenticated" would be
     * certifying a coin flip: the same cipher hands the row over whenever the
     * flipped byte lands somewhere JSON tolerates. So the question is put where it
     * can only have one answer.
     *
     * The byte is changed three quarters of the way in, past any key identifier or
     * nonce header the sealed form carries at the front, so what is modified is
     * the sealed payload rather than the envelope around it — a refusal that came
     * only from an unrecognised key id would prove something much weaker than this
     * subject claims.
     */
    private static function modifiedSealRefused(
        EncryptorInterface $encryptor,
        ?string $sealed,
    ): ExecutedSubject {
        if ($sealed === null || $sealed === '') {
            return ExecutedSubject::failed(
                'a modified row is refused',
                'This deployment stored no sealed form for the personal-data field, so there is '
                    . 'nothing to modify and nothing was sealed.',
            );
        }

        // Always in range: intdiv(3L, 4) is at most L - 1 for every L >= 1, and L
        // is not zero here.
        $index = intdiv(strlen($sealed) * 3, 4);
        $modified = $sealed;
        $modified[$index] = $sealed[$index] === 'A' ? 'B' : 'A';

        try {
            $opened = $encryptor->decrypt($modified);
        } catch (Throwable) {
            return ExecutedSubject::passed(
                'a modified row is refused',
                'One modified byte in the stored form was refused, so the personal data at rest is '
                    . 'authenticated and cannot be edited by whoever can reach the storage.',
            );
        }

        return ExecutedSubject::failed(
            'a modified row is refused',
            sprintf(
                'This deployment opened a personal-data field with one byte changed and returned '
                    . '%d bytes, so what it stores is not authenticated: whoever can write the '
                    . 'storage can change what the application reads back about a data subject.',
                strlen($opened),
            ),
        );
    }

    /**
     * The same personal-data value must not seal to the same bytes twice.
     *
     * The property a database of personal data needs and that neither "integrity"
     * nor "confidentiality" names on its own. A deterministic seal passes every
     * subject above and still discloses, to anyone holding the storage, which data
     * subjects share a postcode, a diagnosis, an employer or a guardian — an
     * equality oracle over the whole table, obtained without opening a single
     * record.
     *
     * The second seal is taken from the SAME context object, so the value sealed
     * is identical by construction and any difference in the stored form comes
     * from the deployment rather than from the input.
     */
    private static function equalValuesDoNotSealAlike(
        ClassifiedContext $context,
        EncryptorInterface $encryptor,
        ?string $sealed,
    ): ExecutedSubject {
        if ($sealed === null) {
            return ExecutedSubject::failed(
                'equal values do not seal alike',
                'This deployment stored no sealed form for the personal-data field, so there is '
                    . 'no stored form to compare a second one against.',
            );
        }

        try {
            $again = self::storedForm($context->serialize($encryptor));
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'equal values do not seal alike',
                sprintf(
                    'Sealing the same personal-data value a second time failed: %s.',
                    $failure->getMessage(),
                ),
            );
        }

        if ($again === null) {
            return ExecutedSubject::failed(
                'equal values do not seal alike',
                'Sealing the same personal-data value a second time stored something that is not '
                    . 'a sealed form.',
            );
        }

        return $again === $sealed
            ? ExecutedSubject::failed(
                'equal values do not seal alike',
                'The same personal-data value sealed twice produced identical stored forms, so '
                    . 'whoever holds the storage can tell which data subjects share a value '
                    . 'without opening a single record.',
            )
            : ExecutedSubject::passed(
                'equal values do not seal alike',
                'The same personal-data value sealed twice produced different stored forms, so '
                    . 'equal values cannot be matched up across records by whoever holds the '
                    . 'storage.',
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
                'A synthetic value was classified as personal data and put through the at-rest '
                    . 'protection this deployment applies to that classification: the stored form '
                    . 'concealed it, it opened to the value byte for byte, one modified byte was '
                    . 'refused, and the same value sealed twice did not repeat itself. %d '
                    . 'subject(s) ran, and nothing was written to any store.',
                count($results),
            )
            : 'This deployment did not protect a field it was told holds personal data — '
                . implode(' | ', $failed);
    }
}
