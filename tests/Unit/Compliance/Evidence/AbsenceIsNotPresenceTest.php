<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\InadmissibleEvidenceException;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\SubjectAbsence;
use Pulsar\Compliance\Evidence\DatabaseTlsObserver;
use Pulsar\Config\ConnectionConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\SecurityConfig;
use Pulsar\Database\Driver;
use Pulsar\Security\Posture\SecurityPostureCheck;
use Pulsar\Security\Posture\SecurityPostureItem;
use Pulsar\Security\Posture\SecurityPostureStatus;
use Pulsar\Security\Posture\SecurityRuntimeBindings;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;
use Pulsar\Tests\Unit\Database\Stub\InMemoryConnection;

use function array_values;
use function sprintf;

/**
 * Run each observer against a deployment that does not have the thing it
 * observes, and prove it says so.
 *
 * The defect these assertions exist for was uniform in shape and appeared at the
 * strongest grade the vocabulary offers: an observer met an absent subject,
 * found nothing wrong — correctly, there was nothing there to be wrong — and
 * reported the control as holding. Three instances, all live in this repository
 * before this change:
 *
 *  - no database configured at all: `present: true`, "there is no transport to
 *    encrypt";
 *  - a SQLite-only deployment: `present: true` at grade Measured, "SQLite is a
 *    local file; the session has no network transport to encrypt";
 *  - `debug_mode / present: true` beside the reason "Debug mode is enabled
 *    (expected outside production)".
 *
 * Each of the three is asserted here from the negative side — the deployment
 * lacking the subject — because that is the side nobody exercised.
 */
#[CoversClass(DatabaseTlsObserver::class)]
#[CoversClass(SubjectAbsence::class)]
final class AbsenceIsNotPresenceTest extends TestCase
{
    /** Sixty-four hex characters, so the master-key item is not the one under test. */
    private const string STRONG_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // -- The database transport observer -------------------------------------

    #[Test]
    public function aDeploymentWithNoDatabaseHasNoTransportRatherThanAnEncryptedOne(): void
    {
        $observation = new DatabaseTlsObserver()->observe(null, null);

        self::assertFalse(
            $observation->subjectExists,
            'A deployment with no database has no database transport. Reporting one is how '
                . 'an encryption control was satisfied by a deployment that encrypts nothing.',
        );
        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
        self::assertStringContainsString('No database connection is configured', $observation->detail);
        self::assertStringContainsString('nothing here to encrypt', $observation->detail);
    }

    #[Test]
    public function anEmptyConnectionListIsTreatedTheSameWayAsNoConfigAtAll(): void
    {
        $observation = new DatabaseTlsObserver()->observe(self::database([]), null);

        self::assertFalse($observation->subjectExists);
        self::assertFalse($observation->present);
    }

    /**
     * The one that carried PCI Req 2.3 in this repository, reproduced end to end:
     * a SQLite-only deployment WITH a live connection, which is the arrangement in
     * which the observer used to ask the file what its session had negotiated and
     * be told "encrypted".
     */
    #[Test]
    public function aFileBackedDeploymentHasNoNetworkTransportRatherThanAnEncryptedOne(): void
    {
        $observation = new DatabaseTlsObserver()->observe(
            self::database([self::connection('sqlite', Driver::SQLite, host: '')]),
            new InMemoryConnection(Driver::SQLite),
        );

        self::assertFalse(
            $observation->subjectExists,
            'SQLite is a local file. "The session has no network transport to encrypt" is the '
                . 'absence of a transport, not an encrypted one, and it must not be admissible.',
        );
        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
        self::assertStringContainsString('crosses a network', $observation->detail);
    }

    /**
     * A connection reached over a Unix socket is local IPC, so a deployment whose
     * only connection is one of those is subjectless too — and the path must not
     * be reachable by a networked connection standing beside it.
     */
    #[Test]
    public function aSocketOnlyDeploymentIsSubjectlessButOneNetworkedConnectionIsEnoughToDecide(): void
    {
        $observer = new DatabaseTlsObserver();

        $socketOnly = $observer->observe(
            self::database([self::connection('pg-socket', Driver::PostgreSQL, host: '/var/run/postgresql')]),
            null,
        );

        self::assertFalse($socketOnly->subjectExists);

        $mixed = $observer->observe(
            self::database([
                self::connection('pg-socket', Driver::PostgreSQL, host: '/var/run/postgresql'),
                self::connection('pg-remote', Driver::PostgreSQL, host: 'db.internal'),
            ]),
            null,
        );

        self::assertTrue(
            $mixed->subjectExists,
            'One networked connection is a transport, whatever else is configured beside it. '
                . 'A subject that exists must never be excused by a neighbour that does not.',
        );
        self::assertFalse($mixed->present, 'The remote connection carries no TLS setting.');
        self::assertSame(ObservationGrade::Declared, $mixed->grade);
    }

    #[Test]
    public function aNetworkedDeploymentStillReportsPlaintextAsAGapAndNotAsAnAbsence(): void
    {
        $observation = new DatabaseTlsObserver()->observe(
            self::database([self::connection('mysql', Driver::MySQL, host: '10.0.0.5')]),
            null,
        );

        self::assertTrue($observation->subjectExists, 'There is a transport here; it is simply unprotected.');
        self::assertFalse($observation->present);
        self::assertStringContainsString('travel in the clear', $observation->detail);
    }

    // -- The security posture facts ------------------------------------------

    /**
     * `Ok` answers "should this deployment be stopped over it?", and outside
     * production it answers no while the weakness plainly stands. The evidence
     * gatherer read `Ok` as "the control holds" and published `present: true`
     * beside a reason saying the opposite.
     */
    #[Test]
    public function anEnvironmentRelaxedPostureItemIsOkAndIsNotTheControlHolding(): void
    {
        $item = self::postureItem(self::developmentPosture(), 'debug_mode');

        self::assertSame(
            SecurityPostureStatus::Ok,
            $item->status,
            'Status must stay Ok: security:check and deploy:check gate on severity, and local '
                . 'development is not failed over debug mode.',
        );
        self::assertTrue(
            $item->relaxed,
            'Debug mode is ON. An item that is Ok only because of the environment must say so, '
                . 'or a consumer asking "does the control hold?" gets the answer to a different '
                . 'question.',
        );
        self::assertNotSame('', $item->fix);
    }

    #[Test]
    public function theRelaxedItemsAreExactlyTheOnesWhoseReasonSaysTheControlDoesNotHold(): void
    {
        $relaxed = [];

        foreach (self::developmentPosture() as $item) {
            if ($item->relaxed) {
                $relaxed[] = $item->name;
            }
        }

        self::assertSame(
            ['debug_mode', 'https_hsts', 'session_cookie_secure'],
            $relaxed,
            'These three were Ok with reasons reading "not enforced outside production", '
                . '"Debug mode is enabled (expected outside production)" and "Secure flag '
                . 'relaxed outside production". Any new one must be a deliberate addition.',
        );
    }

    #[Test]
    public function aProductionPostureRelaxesNothing(): void
    {
        foreach (self::productionPosture() as $item) {
            self::assertFalse(
                $item->relaxed,
                sprintf('Nothing may be relaxed in production; %s was.', $item->name),
            );
        }
    }

    // -- The absence material itself -----------------------------------------

    #[Test]
    public function anAbsenceCannotBeClaimedOverAPopulationThatHasMembers(): void
    {
        $this->expectException(InadmissibleEvidenceException::class);
        $this->expectExceptionMessageMatches('/the enumeration it was built from returned 2/');

        // Through the acknowledged escape, because noneIn() is sealed to the
        // component that measures and this test is not it. The refusal being
        // proved is the one on the VALUE: an absence claimed over an estate with
        // members is refused at the constructor, so neither the gatherer nor a
        // fixture can retire a live requirement by declaring its subject
        // imaginary.
        (void) ReflectedVocabulary::construct(SubjectAbsence::class, [
            'database connections that cross a network',
            'There is no transport here.',
            2,
        ]);
    }

    #[Test]
    public function aSubjectlessObservationCanNeverBeAdmissibleHoweverItIsSpelled(): void
    {
        $observation = SyntheticObservation::withoutSubject(
            ObservationId::DatabaseTransportEncrypted,
            'There is no database.',
        );

        self::assertFalse($observation->subjectExists);
        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
    }

    /**
     * The outcome a subjectless control reaches, named here so the exit-code
     * contract is asserted somewhere: NotApplicable is not a gap and does not
     * enter the coverage denominator, so a framework the deployment does not
     * exercise neither fails the gate nor inflates the pass rate.
     */
    #[Test]
    public function aControlWithNoSubjectIsNeitherAFailureNorAPass(): void
    {
        self::assertFalse(ControlOutcome::NotApplicable->isGap());
        self::assertFalse(ControlOutcome::NotApplicable->countsTowardCoverage());
        self::assertTrue(ControlOutcome::Unsatisfied->isGap());
        self::assertTrue(ControlOutcome::Satisfied->countsTowardCoverage());
    }

    // -- Fixtures -------------------------------------------------------------

    /**
     * @param list<ConnectionConfig> $connections
     */
    private static function database(array $connections): DatabaseConfig
    {
        $keyed = [];

        foreach ($connections as $connection) {
            $keyed[$connection->name] = $connection;
        }

        return new DatabaseConfig(
            defaultConnection: $connections === [] ? '' : $connections[0]->name,
            connections: $keyed,
            migrationsTable: 'migrations',
            migrationsPath: 'database/migrations',
        );
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function connection(
        string $name,
        Driver $driver,
        string $host,
        array $options = [],
    ): ConnectionConfig {
        return new ConnectionConfig(
            name: $name,
            driver: $driver,
            host: $host,
            port: 5432,
            database: 'pulsar',
            username: 'pulsar',
            password: '',
            charset: 'utf8',
            collation: '',
            options: $options,
        );
    }

    /**
     * @param list<SecurityPostureItem> $items
     */
    private static function postureItem(array $items, string $name): SecurityPostureItem
    {
        foreach ($items as $item) {
            if ($item->name === $name) {
                return $item;
            }
        }

        self::fail(sprintf('The posture preflight produced no "%s" item.', $name));
    }

    /**
     * A local development deployment: debug on, no HSTS, an HTTP-only cookie —
     * every weakness the posture check declines to fail outside production.
     *
     * @return list<SecurityPostureItem>
     */
    private static function developmentPosture(): array
    {
        return array_values(new SecurityPostureCheck(
            self::securityConfig(cookieSecure: false, hstsEnabled: false),
            isProduction: false,
            debugMode: true,
            masterKey: self::STRONG_KEY,
            bindings: new SecurityRuntimeBindings(
                masterKeyBound: true,
                encryptorBound: true,
                sessionEncryptionBound: true,
            ),
        )->evaluate()->items);
    }

    /**
     * The same weaknesses in production, where none of them may be relaxed: they
     * are failures there, and a failure already says the control does not hold.
     *
     * @return list<SecurityPostureItem>
     */
    private static function productionPosture(): array
    {
        return array_values(new SecurityPostureCheck(
            self::securityConfig(cookieSecure: false, hstsEnabled: false),
            isProduction: true,
            debugMode: true,
            masterKey: self::STRONG_KEY,
            bindings: new SecurityRuntimeBindings(
                masterKeyBound: true,
                encryptorBound: true,
                sessionEncryptionBound: true,
            ),
        )->evaluate()->items);
    }

    private static function securityConfig(bool $cookieSecure, bool $hstsEnabled): SecurityConfig
    {
        return SecurityConfig::fromArray([
            'session' => [
                'encryption' => true,
                'cookie_secure' => $cookieSecure,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
            ],
            'csrf' => ['enabled' => true],
            'headers' => ['hsts' => ['enabled' => $hstsEnabled, 'max_age' => 63_072_000]],
            'rate_limiting' => ['enabled' => false],
        ], Environment::load());
    }
}
