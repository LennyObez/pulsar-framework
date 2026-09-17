<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Security\Compliance\Pseudonymization\ForgetServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface;
use Random\Randomizer;
use Throwable;

use function bin2hex;
use function count;
use function hash_equals;
use function implode;
use function sprintf;
use function str_contains;
use function strlen;

/**
 * Whether a direct identifier is actually replaced, resolved back, and erased.
 *
 * WHY THIS EXISTS. `composer compliance:check` failed on a default installation
 * because GDPR Art 25 was claimed and not observed, and ADR-0046 left that
 * failure standing rather than configuring it away. The reason it could not be
 * closed was precise: the control rested on
 * {@see PseudonymizationServiceInterface} RESOLVING to `PseudonymizationService`,
 * which says which class would serve a request and never that a direct identifier
 * was replaced. That is ADR-0041's defect — "the class is bound" is "the class
 * exists" with a longer sentence — and config/compliance.php named the only
 * honest way out: an observer that puts a value through the service, as
 * {@see TokenVaultObserver} already does for the token vault.
 *
 * WHAT IT RUNS, in order, all four against the live service:
 *
 *   1. pseudonymise a synthetic identifier — the pseudonym must not be, or
 *      contain, the identifier it stands for
 *   2. the mapping must be on record afterwards — a pseudonym derived and
 *      forgotten cannot answer anything later
 *   3. resolve — the identifier must come back, byte for byte, because Art 4(5)
 *      pseudonymisation is reversible BY ITS HOLDER and an irreversible hash is a
 *      different measure with different consequences
 *   4. forget — and the mapping must then resolve to nothing
 *
 * STEP 4 IS THE CONTROL AND THE CLEANUP AT ONCE, which is the reason this
 * measurement can write to a production table with a clear conscience. Art 17
 * erasure is what {@see ForgetServiceInterface} exists to perform, so the step
 * that removes what this check wrote is the same step that evidences the
 * requirement. It runs in a `finally`, so a failure at 1, 2 or 3 still erases;
 * and if the erasure itself fails, the report says so as a failed subject and
 * names the identifier left behind, rather than staying quiet about a row it
 * added to a re-identification table. What remains afterwards is a table holding
 * no mapping this check created — on a deployment that had never pseudonymised
 * anything, an empty table file where there had been none, which is what the
 * first real `pseudonymize()` would have produced anyway.
 *
 * WHAT IS WRITTEN, and how small it is kept: one mapping, whose subject id is
 * {@see PROBE_SUBJECT_PREFIX} followed by 32 random hex characters from the
 * framework's CSPRNG. It is not derived from anything the deployment holds, it
 * cannot collide with a real subject, and a human reading the table sees what it
 * is. Two audit entries are also written — `PseudonymizationService::resolve()`
 * and `ForgetService::forget()` both record what they did — and that is a
 * feature: the erasure this check performs is itself evidenced in the trail, with
 * the confirmation hash the erasure returned.
 *
 * WHAT IT CANNOT SEE, stated because the gap is load-bearing and is carried by a
 * fact beside it rather than by prose here: this runs in ONE process, so a
 * service standing on the development stub `InMemoryPseudonymLookup` would pass
 * all four subjects and have forgotten every mapping by the next request. That is
 * exactly the shape ADR-0041 was written about, and it is why
 * {@see ObservationId::PseudonymTablePersistence} is a separate, ESSENTIAL fact
 * in {@see \Pulsar\Compliance\Probe\PseudonymizationProbe}: durability is a
 * question about which table answered, and no in-process measurement can ask it.
 *
 * AND WHAT NO OBSERVER HERE CAN SEE AT ALL. Art 25 says "by design and by
 * default", over the whole of a controller's processing. Pulsar knows that its
 * own pseudonymisation primitive works; it does not know whether the application
 * puts its identifiers through it, and nothing in this tree could find out. The
 * control's requirement text says so where a reader of the report will see it,
 * which is the same narrowing PCI Req 3.4 carries.
 *
 * @see docs/adr/0065-an-incident-register-is-measured-by-recording-something.md
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class PseudonymizationObserver
{
    /**
     * The prefix every identifier this check mints carries.
     *
     * Public so that an operator who finds one in the table — which happens only
     * when the erasure step failed, and the report says so when it does — can
     * grep for the class that put it there.
     */
    public const string PROBE_SUBJECT_PREFIX = 'compliance.pseudonym_probe:';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live pseudonymisation service';

    /** Bytes of randomness in the synthetic identifier; 16 bytes = 32 hex characters. */
    private const int PROBE_IDENTIFIER_BYTES = 16;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * Exercise pseudonymisation and erasure, or report that there was none to
     * exercise.
     *
     * @param PseudonymizationServiceInterface|null $service The service that serves
     *        requests, resolved by the composition root. Null when the audit and
     *        crypto stack never came up — which is a fact, not a pass
     * @param ForgetServiceInterface|null           $erasure The Art 17 erasure the
     *        deployment would actually perform. Taken separately rather than assumed,
     *        even though `SecurityWiring` binds both in the same block: a deployment
     *        that overrides one and not the other is expressible, and a check that
     *        erased through the mapping table directly would evidence its own tidiness
     *        instead of the control
     */
    #[NoDiscard]
    public function observe(
        ?PseudonymizationServiceInterface $service,
        ?ForgetServiceInterface $erasure,
    ): Observation {
        if ($service === null || $erasure === null) {
            return Observation::measured(
                ObservationId::IdentifierPseudonymizedAndErased,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    self::nothingToExercise($service, $erasure),
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::IdentifierPseudonymizedAndErased,
            $this->exercise($service, $erasure),
            self::class,
        );
    }

    /**
     * Which half of the subsystem was missing, named rather than summarised.
     *
     * "Pseudonymisation was not exercised" sends an operator to look at both; the
     * two absences have different causes and different remedies, and a report that
     * printed the same sentence for them would be hiding the difference.
     *
     * @return non-empty-string
     */
    private static function nothingToExercise(
        ?PseudonymizationServiceInterface $service,
        ?ForgetServiceInterface $erasure,
    ): string {
        if ($service === null && $erasure === null) {
            return 'Neither a pseudonymisation service nor an erasure service is in service, '
                . 'so no identifier was replaced and none could have been erased.';
        }

        if ($service === null) {
            return 'No pseudonymisation service is in service, so nothing replaced a direct '
                . 'identifier. An erasure service is bound and had nothing to erase.';
        }

        return 'A pseudonymisation service is in service and no erasure service is, so a '
            . 'mapping this deployment creates could not be erased on request. Nothing was '
            . 'exercised: minting an identifier that cannot be erased again would leave one '
            . 'behind in the mapping table.';
    }

    /**
     * Run the subjects, erasing whatever was created.
     *
     * A throw from pseudonymize() is reported as a subject that RAN and failed,
     * not as a run that could not happen: the service was called and it refused.
     * That is the distinction a service holding no derived key falls on, and
     * collapsing it would report an unusable subsystem and an absent one the same
     * way.
     */
    private function exercise(
        PseudonymizationServiceInterface $service,
        ForgetServiceInterface $erasure,
    ): Measurement {
        $secret = bin2hex($this->randomizer->getBytes(self::PROBE_IDENTIFIER_BYTES));
        $subjectId = self::PROBE_SUBJECT_PREFIX . $secret;

        try {
            $pseudonym = $service->pseudonymize($subjectId);
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'pseudonymize',
                    sprintf(
                        'The service refused to pseudonymise a synthetic identifier: %s. Nothing '
                            . 'this deployment processes has a direct identifier replaced by it.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'The pseudonymisation service is bound but not usable: pseudonymize() failed '
                        . 'with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $results = [];

        try {
            $results[] = self::identifierReplaced($pseudonym, $secret);
            $results[] = self::mappingOnRecord($service, $subjectId);
            $results[] = self::pseudonymResolves($service, $pseudonym, $subjectId);
        } finally {
            $results[] = self::mappingErased($service, $erasure, $subjectId, $pseudonym);
        }

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * The pseudonym that stands in for the identifier must not carry it.
     *
     * Checked against the RANDOM half rather than the whole subject id, so a
     * service that stripped the probe prefix and returned the rest would still
     * fail: what must not survive is the part that identifies.
     */
    private static function identifierReplaced(string $pseudonym, string $secret): ExecutedSubject
    {
        if ($pseudonym === '') {
            return ExecutedSubject::failed(
                'the identifier is replaced',
                'The service returned an empty pseudonym, so the identifier was replaced by '
                    . 'nothing at all.',
            );
        }

        return str_contains($pseudonym, $secret)
            ? ExecutedSubject::failed(
                'the identifier is replaced',
                'The pseudonym the service returned contains the identifier it was meant to '
                    . 'replace, so nothing was pseudonymised.',
            )
            : ExecutedSubject::passed(
                'the identifier is replaced',
                sprintf(
                    'The service returned a %d-character pseudonym carrying none of the '
                        . 'identifier it stands for.',
                    strlen($pseudonym),
                ),
            );
    }

    /**
     * A pseudonym the service does not remember minting is not a pseudonym.
     *
     * Art 4(5) calls the mapping "additional information" that has to be kept, and
     * kept separately: a service that derives a value and retains nothing cannot
     * answer an Art 15 request about the pseudonymised record, and has nothing for
     * Art 17 to erase.
     */
    private static function mappingOnRecord(
        PseudonymizationServiceInterface $service,
        string $subjectId,
    ): ExecutedSubject {
        try {
            $recorded = $service->exists($subjectId);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the mapping is on record',
                sprintf(
                    'The service could not say whether the mapping it had just created exists: %s.',
                    $failure->getMessage(),
                ),
            );
        }

        return $recorded
            ? ExecutedSubject::passed(
                'the mapping is on record',
                'The mapping the service created is on record, so the pseudonym stands for '
                    . 'something the controller can still find.',
            )
            : ExecutedSubject::failed(
                'the mapping is on record',
                'The service reports no mapping for an identifier it had just pseudonymised, so '
                    . 'the pseudonym stands for nothing and cannot be resolved or erased.',
            );
    }

    /**
     * The mapping must be reversible by its holder, or it is not pseudonymisation.
     *
     * The distinction is the whole of Art 4(5): data that cannot be attributed to
     * a subject WITHOUT the additional information is pseudonymised, and data that
     * cannot be attributed to a subject at all is anonymised, which is a different
     * measure with different obligations. A service returning something other than
     * the identifier has not replaced it, it has lost it.
     */
    private static function pseudonymResolves(
        PseudonymizationServiceInterface $service,
        string $pseudonym,
        string $subjectId,
    ): ExecutedSubject {
        try {
            $recovered = $service->resolve($pseudonym);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the pseudonym resolves to its subject',
                sprintf(
                    'Resolving the pseudonym the service had just minted failed: %s.',
                    $failure->getMessage(),
                ),
            );
        }

        if ($recovered === null) {
            return ExecutedSubject::failed(
                'the pseudonym resolves to its subject',
                'The service returned nothing for a pseudonym it had just minted, so the mapping '
                    . 'did not survive the call that created it.',
            );
        }

        return hash_equals($subjectId, $recovered)
            ? ExecutedSubject::passed(
                'the pseudonym resolves to its subject',
                'The service returned the original identifier byte for byte, so the replacement '
                    . 'is reversible by the controller that holds the mapping.',
            )
            : ExecutedSubject::failed(
                'the pseudonym resolves to its subject',
                'The service resolved the pseudonym to something other than the identifier it '
                    . 'replaced, so what it holds cannot be attributed to the right subject.',
            );
    }

    /**
     * The mapping must go away when the subject asks, and this is where it does.
     *
     * BOTH THE CONTROL AND THE CLEANUP. Art 17 erasure is what
     * {@see ForgetServiceInterface} exists to perform, so nothing extra is written
     * to satisfy tidiness: the requirement and the removal are one call. Erasure
     * is asserted rather than assumed — the service is asked afterwards whether
     * the identifier and the pseudonym still resolve, because `forget()` returning
     * a confirmation hash is a report, and a report is what this whole subsystem
     * exists to stop accepting in place of an observation.
     */
    private static function mappingErased(
        PseudonymizationServiceInterface $service,
        ForgetServiceInterface $erasure,
        string $subjectId,
        string $pseudonym,
    ): ExecutedSubject {
        try {
            $result = $erasure->forget($subjectId);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the mapping is erased on request',
                sprintf(
                    'The erasure service refused to forget the synthetic identifier this check '
                        . 'created (%s); one mapping under "%s" is left in the table.',
                    $failure->getMessage(),
                    self::PROBE_SUBJECT_PREFIX,
                ),
            );
        }

        try {
            $lingering = $service->exists($subjectId);
            $stillResolves = $service->resolve($pseudonym) !== null;
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the mapping is erased on request',
                sprintf(
                    'The erasure reported success and the service could not then be asked whether '
                        . 'the mapping is gone: %s.',
                    $failure->getMessage(),
                ),
            );
        }

        if ($lingering || $stillResolves) {
            return ExecutedSubject::failed(
                'the mapping is erased on request',
                sprintf(
                    'forget() reported success and the mapping still %s; one mapping under "%s" '
                        . 'is left in the table and no erasure request can be evidenced.',
                    $stillResolves ? 'resolves' : 'exists',
                    self::PROBE_SUBJECT_PREFIX,
                ),
            );
        }

        return ExecutedSubject::passed(
            'the mapping is erased on request',
            sprintf(
                'The erasure service removed the mapping: the identifier no longer exists, the '
                    . 'pseudonym resolves to nothing, and the deletion is recorded in the audit '
                    . 'trail as entry %s.',
                $result->auditEntryId,
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
                'A synthetic identifier was pseudonymised, recorded, resolved back byte for byte '
                    . 'and then erased through the live services: the pseudonym carried none of '
                    . 'the identifier, and the erasure left nothing behind. %d subject(s) ran.',
                count($results),
            )
            : 'The live pseudonymisation service did not replace, resolve and erase an '
                . 'identifier — ' . implode(' | ', $failed);
    }
}
