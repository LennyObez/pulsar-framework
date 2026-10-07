<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\PseudonymizationObserver;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Compliance\Pseudonymization\FilePseudonymLookup;
use Pulsar\Security\Compliance\Pseudonymization\ForgetResult;
use Pulsar\Security\Compliance\Pseudonymization\ForgetService;
use Pulsar\Security\Compliance\Pseudonymization\ForgetServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymLookupInterface;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Tests\Support\Compliance\RecordingPseudonymLookup;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

use function array_keys;
use function bin2hex;
use function file_get_contents;
use function is_file;
use function json_decode;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * The pseudonymisation measurement, driven directly against services that
 * behave in each of the ways one can.
 *
 * Driven here rather than only through {@see \Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment}
 * for the reason {@see IncidentRegisterObserverTest} gives: the failures that
 * matter are not shapes a composition root can be asked for. A service that
 * returns the identifier as its own pseudonym, one that forgets the mapping the
 * moment it made it, and one whose erasure reports success and deletes nothing
 * are all bound, all resolving to a class an accept list would take, and all
 * fail the requirement.
 *
 * The observer is called directly, which the production seal permits: it is
 * compiled from `src/Compliance/Evidence/`, so it is the component that measures
 * however it was reached.
 */
#[CoversClass(PseudonymizationObserver::class)]
final class PseudonymizationObserverTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];
    }

    #[Test]
    public function noServiceIsAnAbsentFactAndNotAPass(): void
    {
        $observation = self::observer()->observe(null, null);

        self::assertSame(ObservationId::IdentifierPseudonymizedAndErased, $observation->id);
        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('Neither a pseudonymisation service nor an erasure', $observation->detail);
    }

    /**
     * The two halves are named apart, because they have different causes and
     * different remedies.
     */
    #[Test]
    public function anErasureServiceWithoutAPseudonymisationServiceIsSaidSo(): void
    {
        $observation = self::observer()->observe(null, self::erasureThat(
            static fn(): never => throw new RuntimeException('nothing should reach this'),
        ));

        self::assertFalse($observation->present);
        self::assertStringContainsString('No pseudonymisation service is in service', $observation->detail);
    }

    /**
     * A service with no erasure beside it is NOT exercised at all, and the
     * detail says why: minting a mapping that cannot be erased again would leave
     * one behind in a re-identification table.
     */
    #[Test]
    public function aServiceWithNoErasureIsNotExercisedAndTheReasonIsGiven(): void
    {
        $lookup = new RecordingPseudonymLookup();
        $observation = self::observer()->observe(self::serviceOver($lookup), null);

        self::assertFalse($observation->present);
        self::assertStringContainsString('could not be erased on request', $observation->detail);
        self::assertSame([], $lookup->stored, 'Nothing may be minted when it cannot be erased.');
    }

    /**
     * The whole point, against the subsystem a default installation gets.
     */
    #[Test]
    public function theRealServiceReplacesResolvesAndErases(): void
    {
        $lookup = new FilePseudonymLookup($this->tablePath());

        $observation = self::observer()->observe(self::serviceOver($lookup), self::erasureOver($lookup));

        self::assertTrue($observation->present, $observation->detail);
        self::assertTrue($observation->isAdmissibleAsProof());
        self::assertStringContainsString('resolved back byte for byte', $observation->detail);
        self::assertStringContainsString('4 subject(s) ran', $observation->detail);
    }

    /**
     * And it leaves nothing behind, which is what lets it run against a
     * production re-identification table.
     */
    #[Test]
    public function theMeasurementLeavesNoMappingBehind(): void
    {
        $path = $this->tablePath();
        $lookup = new FilePseudonymLookup($path);

        (void) self::observer()->observe(self::serviceOver($lookup), self::erasureOver($lookup));

        /** @var array<string, mixed> $table */
        $table = (array) json_decode((string) file_get_contents($path), true);

        self::assertSame([], array_keys($table), 'The table must hold no mapping the check created.');

        // And through the table itself rather than through the document, so the
        // assertion is about what a later request would find: the subject that
        // was stored is the subject that was deleted.
        $recording = new RecordingPseudonymLookup();
        (void) self::observer()->observe(self::serviceOver($recording), self::erasureOver($recording));

        self::assertCount(1, $recording->stored);
        self::assertSame([], $recording->surviving());
    }

    /**
     * A service that returns the identifier it was given has replaced nothing,
     * however confidently it says it did.
     */
    #[Test]
    public function aPseudonymThatCarriesTheIdentifierFailsTheControl(): void
    {
        $lookup = new InMemoryPseudonymLookup();

        $observation = self::observer()->observe(
            new class ($lookup) implements PseudonymizationServiceInterface {
                public function __construct(private readonly PseudonymLookupInterface $lookup) {}

                #[Override]
                public function pseudonymize(string $subjectId): string
                {
                    $this->lookup->store($subjectId, $subjectId, 'salt');

                    return $subjectId;
                }

                #[Override]
                public function resolve(string $pseudonym): ?string
                {
                    return $this->lookup->findByPseudonym($pseudonym)?->subjectId;
                }

                #[Override]
                public function exists(string $subjectId): bool
                {
                    return $this->lookup->findBySubjectId($subjectId) !== null;
                }
            },
            self::erasureOver($lookup),
        );

        self::assertFalse($observation->present);
        self::assertStringContainsString('contains the identifier it was meant to replace', $observation->detail);
    }

    /**
     * A pseudonym the service does not remember minting stands for nothing: the
     * controller can neither answer an access request about the record nor erase
     * it.
     */
    #[Test]
    public function aMappingThatIsNotRecordedFailsTheControl(): void
    {
        $observation = self::observer()->observe(
            new class implements PseudonymizationServiceInterface {
                #[Override]
                public function pseudonymize(string $subjectId): string
                {
                    return bin2hex(random_bytes(16));
                }

                #[Override]
                public function resolve(string $pseudonym): ?string
                {
                    return null;
                }

                #[Override]
                public function exists(string $subjectId): bool
                {
                    return false;
                }
            },
            self::erasureThat(static fn(): ForgetResult => new ForgetResult(
                confirmationHash: 'nothing-was-there',
                forgottenAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                auditEntryId: 'audit-0',
            )),
        );

        self::assertFalse($observation->present);
        self::assertStringContainsString('stands for nothing', $observation->detail);
    }

    /**
     * The failure that reads as success everywhere else: forget() returns a
     * confirmation hash and the mapping is still there.
     *
     * Erasure is asserted rather than believed, because a confirmation hash is a
     * report, and accepting a report in place of an observation is what this
     * whole subsystem exists to refuse.
     */
    #[Test]
    public function anErasureThatDeletesNothingFailsTheControlAndSaysWhatIsLeft(): void
    {
        $lookup = new RecordingPseudonymLookup();

        $observation = self::observer()->observe(
            self::serviceOver($lookup),
            self::erasureThat(static fn(): ForgetResult => new ForgetResult(
                confirmationHash: 'looks-official',
                forgottenAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                auditEntryId: 'audit-0',
            )),
        );

        self::assertFalse($observation->present);
        self::assertStringContainsString('is left in the table', $observation->detail);
        self::assertStringContainsString(PseudonymizationObserver::PROBE_SUBJECT_PREFIX, $observation->detail);
    }

    /**
     * A service that refuses RAN and refused; it is a different fact from a
     * service that is not there, and the report must not print them the same way.
     */
    #[Test]
    public function aServiceThatRefusesIsBoundAndNotUsable(): void
    {
        $observation = self::observer()->observe(
            new class implements PseudonymizationServiceInterface {
                #[Override]
                public function pseudonymize(string $subjectId): string
                {
                    throw new RuntimeException('the mapping table is not readable JSON');
                }

                #[Override]
                public function resolve(string $pseudonym): ?string
                {
                    return null;
                }

                #[Override]
                public function exists(string $subjectId): bool
                {
                    return false;
                }
            },
            self::erasureThat(static fn(): never => throw new RuntimeException('nothing should reach this')),
        );

        self::assertFalse($observation->present);
        self::assertStringContainsString('bound but not usable', $observation->detail);
        self::assertStringContainsString('not readable JSON', $observation->detail);
    }

    /**
     * The development stub PASSES this measurement, and saying so here is the
     * point: every subject is satisfied inside one process, and the mappings are
     * gone at the end of the request. That is why
     * {@see ObservationId::PseudonymTablePersistence} is a separate essential
     * fact rather than something this observer is asked to notice.
     */
    #[Test]
    public function theDevelopmentStubPassesTheMeasurementAndIsCaughtElsewhere(): void
    {
        $lookup = new InMemoryPseudonymLookup();

        $observation = self::observer()->observe(self::serviceOver($lookup), self::erasureOver($lookup));

        self::assertTrue($observation->present, $observation->detail);
    }

    private static function observer(): PseudonymizationObserver
    {
        return new PseudonymizationObserver(new Randomizer(new Secure()));
    }

    private static function serviceOver(PseudonymLookupInterface $lookup): PseudonymizationServiceInterface
    {
        $masterKey = MasterKey::fromHex(bin2hex(random_bytes(32)));

        return new PseudonymizationService(
            $masterKey,
            $lookup,
            Encryptor::fromMasterKey($masterKey),
            self::auditLogger(),
        );
    }

    private static function erasureOver(PseudonymLookupInterface $lookup): ForgetServiceInterface
    {
        return new ForgetService($lookup, self::auditLogger());
    }

    /**
     * @param callable(string): ForgetResult $onForget
     */
    private static function erasureThat(callable $onForget): ForgetServiceInterface
    {
        return new class ($onForget) implements ForgetServiceInterface {
            /**
             * @param callable(string): ForgetResult $onForget
             */
            public function __construct(private $onForget) {}

            #[Override]
            public function forget(string $subjectId): ForgetResult
            {
                return ($this->onForget)($subjectId);
            }
        };
    }

    /**
     * A real logger over a real sink: `resolve()` and `forget()` both record what
     * they did, and a stub that swallowed those calls would let the check pass
     * with no evidence of the erasure it performed.
     */
    private static function auditLogger(): AuditLoggerInterface
    {
        $directory = sys_get_temp_dir() . '/pulsar_pseudonym_probe_' . bin2hex(random_bytes(6));
        @mkdir($directory, 0o700, true);

        return new AuditLogger(
            new AuditFileSink($directory . '/audit.jsonl'),
            bin2hex(random_bytes(32)),
        );
    }

    private function tablePath(): string
    {
        $directory = sys_get_temp_dir() . '/pulsar_pseudonym_probe_' . bin2hex(random_bytes(6));
        @mkdir($directory, 0o700, true);

        $path = $directory . '/pseudonyms.json';
        $this->written[] = $path;

        return $path;
    }
}
