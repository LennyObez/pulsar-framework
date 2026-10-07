<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use Closure;
use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ContractResolution;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Control\CoverageSummary;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\InadmissibleEvidenceException;
use Pulsar\Compliance\Control\Inspection;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\MeasuringComponent;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\PlatformCapability;
use Pulsar\Compliance\Control\ProbeVerdict;
use Pulsar\Compliance\Control\RequiredFact;
use Pulsar\Compliance\Control\SubjectAbsence;
use Pulsar\Compliance\Control\UnmeasuredSubjectException;
use Pulsar\Compliance\Probe\PanAtRestProbe;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use SplFileInfo;
use Throwable;

use function array_key_exists;
use function array_keys;
use function basename;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function in_array;
use function ksort;
use function serialize;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function unserialize;

use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * The compliance vocabulary, attacked.
 *
 * WHAT THIS FILE USED TO DO, because it explains the shape of what it does now.
 * It walked the public methods of the vocabulary and asserted that no PARAMETER
 * was named like an outcome, a grade or a presence flag. Three reviews then
 * defeated the subsystem — a public `ControlEvidence` constructor, a pair of
 * `passed()`/`failed()` factories taking two strings, a contract discharged by a
 * class NAME — and this file stayed green through every one of them, because none
 * of those parameters is called `$present` and none is typed `ControlOutcome`. A
 * test that enforces the SPELLING of a rule is ADR-0041's defect one meta-level
 * up: it reports the subsystem sealed because the source looks the way a sealed
 * subsystem would look.
 *
 * So this file is in two halves and the second half is the one that was missing.
 *
 * STRUCTURAL. Every class under `src/Compliance/Control` is enumerated from the
 * DIRECTORY, not from a list here, and held to the rule: the constructor is not
 * public, and every public static method that hands back a vocabulary type is
 * classified — SEALED, COMPUTED or DECLARED — with the classification PROVED
 * rather than trusted. A class added tomorrow with a helpful new factory fails
 * the build until someone classifies it, and a factory classified SEALED is
 * attacked below whatever it is called and whatever it returns.
 *
 * ADVERSARIAL. Every attack the three reviews used is written out as executable
 * code that must throw, each test named for what it would have granted. Fifty-one
 * observations composed into an evidence set. A subject that passed, from two
 * strings. A contract discharged by a class name. Membership computed over an
 * invented roster. A live requirement retired as inapplicable. A finding assessed
 * from a fabricated evidence set. And the paths that need no factory at all:
 * `unserialize()`, `var_export()`/`__set_state()` and `clone`.
 *
 * WHAT IS NOT SEALED, stated here rather than left for the next review to find.
 * PHP has no package-private visibility, so the gatherer needs a PUBLIC door and
 * the same door is in everyone's reach; the seal is therefore a runtime check on
 * the calling FILE ({@see MeasuringComponent}) and not a property of the types.
 * Two language features step around it entirely, and no runtime guard inside these
 * classes can stop either, because both write the object's state without asking
 * the class anything:
 *
 *  - `Closure::bind($fn, null, Observation::class)` executes `new Observation(...)`
 *    in the class's own scope. No Reflection, no `@internal` violated, nothing a
 *    static analyser reports. See
 *    {@see aClosureBoundToTheVocabularyScopeReachesThePrivateConstructor()}.
 *  - `newInstanceWithoutConstructor()` plus {@see ReflectionProperty::setValue()}
 *    builds a value whose constructor — and therefore every invariant in it —
 *    never ran. See {@see aValueBuiltWithoutItsConstructorIsHeldToNoneOfItsInvariants()}.
 *
 * Both are proved here in executable form, because a bypass nobody has written
 * down is a bypass the next design will claim it closed. What IS enforceable is
 * that nothing in this repository uses them on this vocabulary, and that is
 * enforced: {@see noProductionFilePiercesTheVocabularysScope()} and
 * {@see onlyTheAcknowledgedEscapesBypassAConstructor()} read the source tree and
 * fail on a new one.
 */
#[CoversClass(ContractResolution::class)]
#[CoversClass(ControlAssessment::class)]
#[CoversClass(ControlDeclaration::class)]
#[CoversClass(ControlEvidence::class)]
#[CoversClass(ControlFinding::class)]
#[CoversClass(ControlRequirement::class)]
#[CoversClass(CoverageSummary::class)]
#[CoversClass(ExecutedSubject::class)]
#[CoversClass(InadmissibleEvidenceException::class)]
#[CoversClass(Inspection::class)]
#[CoversClass(Measurement::class)]
#[CoversClass(MeasuringComponent::class)]
#[CoversClass(Observation::class)]
#[CoversClass(ObservationGrade::class)]
#[CoversClass(PlatformCapability::class)]
#[CoversClass(ProbeVerdict::class)]
#[CoversClass(RequiredFact::class)]
#[CoversClass(SubjectAbsence::class)]
#[CoversClass(UnmeasuredSubjectException::class)]
final class OutcomeSealTest extends TestCase
{
    /** Where the vocabulary lives, relative to the package root. */
    private const string VOCABULARY = 'src/Compliance/Control/';

    /** The namespace those files declare. */
    private const string VOCABULARY_NAMESPACE = 'Pulsar\\Compliance\\Control\\';

    /** The substring that tells the source scan a file can reach the vocabulary at all. */
    private const string NAMES_THE_VOCABULARY = 'Compliance\\Control';

    /**
     * It produces a fact, so it must refuse every caller that is not compiled from
     * `src/Compliance/Evidence`. Attacked by
     * {@see everyDoorClassifiedSealedRefusesACallerThatIsNotMeasuring()}.
     */
    private const string SEALED = 'sealed';

    /**
     * It derives a value from inputs that are themselves sealed, so there is
     * nothing left to forge. Held to
     * {@see everyDoorClassifiedComputedClaimsNothingWhenHandedNothing()}: given the
     * emptiest input its signature admits, it must claim nothing about a
     * deployment.
     */
    private const string COMPUTED = 'computed';

    /**
     * It states what a control NEEDS, which is a thing a mapping author genuinely
     * knows and must be able to say. Held to
     * {@see everyDoorClassifiedDeclaredHandsBackAValueThatStatesNothing()}: the
     * value it returns must carry no grade, no outcome and no observation, so a
     * fact producer cannot be filed under this word to escape the attack.
     */
    private const string DECLARED = 'declared';

    /**
     * Every public static method in the vocabulary that hands back a vocabulary
     * type, and which of the three things it is.
     *
     * Compared against the DIRECTORY by
     * {@see everyPublicDoorInTheDirectoryIsClassified()}, which is the part of this
     * file that catches the next design rather than this one. The comparison is on
     * the whole set, so a door removed is as loud as a door added.
     *
     * @var array<string, string>
     */
    private const array DOORS = [
        'ContractResolution::answeredBy' => self::SEALED,
        'ContractResolution::unanswered' => self::SEALED,
        'ControlAssessment::summarize' => self::COMPUTED,
        'ControlDeclaration::operatorResponsibility' => self::DECLARED,
        'ControlDeclaration::probed' => self::DECLARED,
        'ControlEvidence::gathered' => self::SEALED,
        'ControlFinding::assess' => self::COMPUTED,
        'ControlRequirement::of' => self::DECLARED,
        'CoverageSummary::over' => self::COMPUTED,
        'ExecutedSubject::failed' => self::SEALED,
        'ExecutedSubject::passed' => self::SEALED,
        'Inspection::coverage' => self::SEALED,
        'Inspection::defectScan' => self::SEALED,
        'Inspection::membership' => self::SEALED,
        'Inspection::nothingToInspect' => self::SEALED,
        'Measurement::completed' => self::SEALED,
        'Measurement::couldNotRun' => self::SEALED,
        'Observation::assertedInScope' => self::SEALED,
        'Observation::assertedOutOfScope' => self::SEALED,
        'Observation::available' => self::SEALED,
        'Observation::declaredMet' => self::SEALED,
        'Observation::declaredUnmet' => self::SEALED,
        'Observation::inspected' => self::SEALED,
        'Observation::measured' => self::SEALED,
        'Observation::noSubject' => self::SEALED,
        'Observation::resolved' => self::SEALED,
        'PlatformCapability::absent' => self::SEALED,
        'PlatformCapability::notInspected' => self::SEALED,
        'PlatformCapability::offered' => self::SEALED,
        'ProbeVerdict::reach' => self::COMPUTED,
        'RequiredFact::contributing' => self::DECLARED,
        'RequiredFact::essential' => self::DECLARED,
        'SubjectAbsence::noneIn' => self::SEALED,
    ];

    /**
     * Classes in the directory whose constructor is public, and why that is not a
     * door. Each one is held to
     * {@see noServiceCanProduceAFindingWithoutSealedEvidence()}.
     *
     * @var array<string, non-empty-string>
     */
    private const array SERVICES = [
        'ControlAssessment' => 'The engine. The composition root builds it with a catalog of '
            . 'declarations and nothing else; it holds no fact, and every method of it that '
            . 'returns findings takes the sealed evidence set as an argument, so it can assess '
            . 'a deployment and cannot describe one.',
    ];

    /**
     * A value that records something about a deployment, or the arithmetic over
     * such values. Nothing here may appear as a property of a DECLARED value: a
     * declaration says what a control needs, never what a deployment did.
     *
     * @var list<class-string>
     */
    private const array FACT_BEARING = [
        ContractResolution::class,
        ControlEvidence::class,
        ControlFinding::class,
        ControlOutcome::class,
        CoverageSummary::class,
        ExecutedSubject::class,
        Inspection::class,
        Measurement::class,
        Observation::class,
        ObservationGrade::class,
        PlatformCapability::class,
        ProbeVerdict::class,
        SubjectAbsence::class,
    ];

    /**
     * The ways PHP hands you an object without calling its constructor, each
     * attempted against every value in the directory by
     * {@see noValueCanBeReopenedWithoutItsConstructor()}.
     *
     * @var list<non-empty-string>
     */
    private const array REOPENING_PATHS = ['unserialize', '__set_state', 'clone'];

    /**
     * Source files allowed to bypass a constructor or bind a closure to a class
     * scope, with the reason. Held to
     * {@see onlyTheAcknowledgedEscapesBypassAConstructor()}.
     *
     * Two of the four have nothing to do with the vocabulary: they build hollow
     * DEPLOYMENT objects — a token store, a session encryptor — so a test can ask
     * the gatherer what it makes of a container without standing up a database. The
     * scan cannot tell whose scope is being pierced from the file's text, so it
     * asks for a reason instead of guessing, and these two write theirs down.
     *
     * @var array<string, non-empty-string>
     */
    private const array MAY_PIERCE = [
        'tests/Support/Compliance/ReflectedVocabulary.php' => 'The acknowledged escape. Fixtures '
            . 'must describe deployments nobody ran, and no factory will produce one; the escape '
            . 'is concentrated in this one file so that a fabrication is visible as such.',
        'tests/Unit/Compliance/Control/OutcomeSealTest.php' => 'This file. Its subject is the '
            . 'escapes, and it proves each one still works so that no later design can claim to '
            . 'have closed what it has not.',
        'tests/Unit/Compliance/Support/DeploymentUnderAssessment.php' => 'Builds hollow instances '
            . 'of the classes a DEPLOYMENT binds, not of the vocabulary: the gatherer reads '
            . '`$instance::class`, so a test needs an object of the right class and nothing more.',
        'tests/Unit/Core/Wiring/ComplianceCatalogWiringTest.php' => 'Binds a hollow '
            . 'DatabaseTokenStore into a container to prove the gatherer observes the deployment '
            . 'at report time rather than at boot. It pierces a token store, not a fact.',
    ];

    /**
     * How a value is reached without ever calling its constructor. `Reflection` is
     * the escape the fixtures use; `Closure::bind` is the one nothing may use.
     *
     * @var list<non-empty-string>
     */
    private const array PIERCING = [
        'Closure::bind',
        '->bindTo(',
        '->call(',
        'newInstanceWithoutConstructor',
        'newInstanceArgs',
        'ReflectionProperty',
        'setAccessible',
    ];

    /**
     * Files that name the vocabulary and pierce a class scope, path => what they use.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $piercingFiles = null;

    // --- The rule, read off the directory -------------------------------------

    /**
     * No value in the vocabulary has a constructor a caller can reach.
     *
     * Enumerated from the directory so a class added later is held to the rule
     * without anyone remembering to add it here. Three kinds of class are exempt
     * and each is exempt for a reason a later reader can check rather than by name:
     * enums and interfaces have no constructor to speak of; exceptions are
     * diagnostics, and {@see noExceptionHandsBackAVocabularyValue()} stops one
     * being used to carry a fact out; and a class with a public constructor must be
     * declared in {@see SERVICES}, which
     * {@see noServiceCanProduceAFindingWithoutSealedEvidence()} then holds to its
     * claim.
     */
    #[Test]
    public function everyValueInTheDirectoryKeepsItsConstructorPrivate(): void
    {
        self::assertNotSame([], self::vocabulary(), 'No control vocabulary was found; the glob is wrong.');

        foreach (self::vocabulary() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isEnum() || $reflection->isInterface() || $reflection->isSubclassOf(Throwable::class)) {
                continue;
            }

            $constructor = $reflection->getConstructor();

            self::assertNotNull($constructor, sprintf('%s has no constructor to seal.', $class));

            if (array_key_exists($reflection->getShortName(), self::SERVICES)) {
                self::assertTrue(
                    $constructor->isPublic(),
                    sprintf(
                        '%s is listed as a service but its constructor is not public. Remove the '
                            . 'SERVICES entry: it is a value now, and the rule applies to it.',
                        $class,
                    ),
                );

                continue;
            }

            self::assertTrue(
                $constructor->isPrivate(),
                sprintf(
                    '%s can be constructed by anyone. Make the constructor private and produce '
                        . 'the value from a factory that asks MeasuringComponent who is calling — '
                        . 'or, if it is a service and holds no fact, add it to SERVICES with the '
                        . 'argument for why.',
                    $class,
                ),
            );
        }
    }

    /**
     * A service may be built by anyone and must still be unable to describe a
     * deployment: every method of it that hands back something about one takes the
     * sealed evidence set as an argument.
     *
     * This is what stops the SERVICES exemption from being the way in. An engine
     * that grew an `assessFromCatalog(): array` — findings out of nothing — fails
     * here, whatever the method is called.
     */
    #[Test]
    public function noServiceCanProduceAFindingWithoutSealedEvidence(): void
    {
        foreach (self::SERVICES as $short => $reason) {
            self::assertNotSame('', $reason, sprintf('The SERVICES entry for %s states no reason.', $short));

            /** @var class-string $class */
            $class = self::VOCABULARY_NAMESPACE . $short;

            foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (!self::returnsSomethingAboutADeployment($method)) {
                    continue;
                }

                self::assertTrue(
                    self::takesASealedValue($method),
                    sprintf(
                        '%s::%s() hands back something about a deployment and asks for nothing '
                            . 'that was gathered. A service is only harmless while it can assess '
                            . 'facts and not state them.',
                        $short,
                        $method->getName(),
                    ),
                );
            }
        }
    }

    /**
     * An exception is a diagnostic about the assessing code, never a fact about a
     * deployment — so none of them may hand a vocabulary value back to whoever
     * catches it.
     *
     * Exceptions are exempt from the constructor rule (they inherit a public one
     * from the SPL and nothing sensible can be done about that), and this is the
     * test that stops the exemption becoming a smuggling channel:
     * `catch (X $e) { $e->observation(); }` is a factory with a `try` around it.
     */
    #[Test]
    public function noExceptionHandsBackAVocabularyValue(): void
    {
        foreach (self::vocabulary() as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->isSubclassOf(Throwable::class)) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $returns = self::vocabularyTypeReturnedBy($method, $class);

                self::assertTrue(
                    $returns === null || $returns === $class,
                    sprintf(
                        '%s::%s() hands back a %s. An exception that carries a fact is a factory '
                            . 'reachable through `catch`.',
                        $reflection->getShortName(),
                        $method->getName(),
                        (string) $returns,
                    ),
                );
            }
        }
    }

    /**
     * Every public static door in the directory is classified, and the
     * classification list holds nothing that is not a door.
     *
     * The enumeration asks what a method RETURNS, across the whole directory, so it
     * finds the ones that hand back a type other than their own —
     * `ControlAssessment::summarize(): CoverageSummary` is a door in a class that is
     * not itself a value, and the previous version of this file did not see it.
     *
     * Note what this test does NOT assert: that no such door exists. It cannot,
     * and pretending otherwise is how the last three designs were written. PHP has
     * no package-private visibility, so the gatherer — a different class, in a
     * different namespace — can only reach a factory that is public, and a rule
     * saying "no public static method returns a vocabulary type" would be satisfied
     * by changing the return type to `mixed`. That is spelling again. The rule
     * enforced instead is that every door is classified and every classification is
     * proved by attacking it.
     */
    #[Test]
    public function everyPublicDoorInTheDirectoryIsClassified(): void
    {
        $found = array_keys(self::doorsInTheDirectory());
        $classified = array_keys(self::DOORS);
        sort($found);
        sort($classified);

        self::assertSame(
            $classified,
            $found,
            'A public door in the control vocabulary is unclassified, or a classified one no '
                . 'longer exists. Decide which of three things it is — SEALED (it produces a '
                . 'fact, so it must refuse anything that is not the measuring component), '
                . 'COMPUTED (it derives a value from sealed inputs) or DECLARED (it states what '
                . 'a control needs and nothing about a deployment) — add it to DOORS, and let '
                . 'the test for that classification attack it.',
        );
    }

    // --- Every classification, proved rather than trusted ---------------------

    /**
     * A sealed door refuses this file, which is not the component that measures.
     *
     * The arguments are synthesized from the door's own reflected signature, so
     * this attack reaches a factory added tomorrow with a signature nobody here
     * anticipated. Where a factory wants material — a {@see Measurement}, an
     * {@see Inspection} — the material is built through the acknowledged escape:
     * the attacker is assumed to have everything the gatherer has, and must still
     * be refused.
     */
    #[Test]
    #[DataProvider('everySealedDoor')]
    public function everyDoorClassifiedSealedRefusesACallerThatIsNotMeasuring(string $door): void
    {
        $method = self::door($door);

        $this->expectException(InadmissibleEvidenceException::class);
        $this->expectExceptionMessageMatches('/is not the component that measures this deployment/');

        $method->invokeArgs(null, self::materialFor($method));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everySealedDoor(): iterable
    {
        foreach (self::DOORS as $door => $classification) {
            if ($classification === self::SEALED) {
                yield $door => [$door];
            }
        }
    }

    /**
     * A computed door, handed the emptiest input its signature admits, claims
     * nothing.
     *
     * That is the whole of what COMPUTED buys: the door stays public because its
     * inputs cannot be forged, so the worst a caller can do is ask it about
     * nothing, and the answer to that must never read like a clean result.
     */
    #[Test]
    #[DataProvider('everyComputedDoor')]
    public function everyDoorClassifiedComputedClaimsNothingWhenHandedNothing(Closure $attack): void
    {
        $attack();
    }

    /**
     * @return iterable<string, array{Closure(): void}>
     */
    public static function everyComputedDoor(): iterable
    {
        foreach (self::attacksOnComputedDoors() as $door => $attack) {
            yield $door => [$attack];
        }
    }

    #[Test]
    public function everyComputedDoorIsAttacked(): void
    {
        $attacked = array_keys(self::attacksOnComputedDoors());
        $classified = [];

        foreach (self::DOORS as $door => $classification) {
            if ($classification === self::COMPUTED) {
                $classified[] = $door;
            }
        }

        sort($attacked);
        sort($classified);

        self::assertSame(
            $classified,
            $attacked,
            'A door is classified COMPUTED and nothing tries it. Write the attack that hands it '
                . 'the emptiest input it accepts, and assert that what comes back claims nothing.',
        );
    }

    /**
     * A declared door hands back a value that says what a control NEEDS and
     * nothing about a deployment: no grade, no outcome, no observation.
     *
     * This is what stops DECLARED being the escape hatch from the seal. A fact
     * producer filed under this word fails here, because the value it returns would
     * have to carry one of the types in {@see FACT_BEARING} to be worth forging.
     *
     * @param class-string $returns
     */
    #[Test]
    #[DataProvider('everyDeclaredDoor')]
    public function everyDoorClassifiedDeclaredHandsBackAValueThatStatesNothing(string $door, string $returns): void
    {
        foreach (new ReflectionClass($returns)->getProperties() as $property) {
            $type = $property->getType();
            $named = $type instanceof ReflectionNamedType ? $type->getName() : '';

            self::assertNotContains(
                $named,
                self::FACT_BEARING,
                sprintf(
                    '%s is classified DECLARED and %s::$%s carries a %s. A declaration that can '
                        . 'hold a fact is a fact producer with a gentler name.',
                    $door,
                    $returns,
                    $property->getName(),
                    $named,
                ),
            );
        }
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function everyDeclaredDoor(): iterable
    {
        $doors = self::doorsInTheDirectory();

        foreach (self::DOORS as $door => $classification) {
            if ($classification === self::DECLARED && array_key_exists($door, $doors)) {
                yield $door => [$door, $doors[$door]];
            }
        }
    }

    // --- The attacks the three reviews used, one per test ---------------------

    /**
     * The attack that opened the whole subsystem: every probe reads its facts out
     * of a {@see ControlEvidence}, so composing one is authorship of the report.
     */
    #[Test]
    public function fiftyOneObservationsWouldHaveGrantedAnEvidenceSetNobodyGathered(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): ControlEvidence => ControlEvidence::gathered(
                ...self::everyFactAtGrade(ObservationGrade::Measured, true),
            ),
            'A whole evidence set was composed from fifty-one observations.',
        );
    }

    /**
     * A caller-supplied boolean spelled as two method names, both taking only
     * strings. Neither references anything that ran.
     */
    #[Test]
    public function twoStringsWouldHaveGrantedASubjectThatPassedWithoutRunning(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): ExecutedSubject => ExecutedSubject::passed('the stored evidence chain', 'Implemented.'),
            'A subject reported as having passed without anything being run.',
        );
    }

    /**
     * The strongest grade in the vocabulary, over a run that never happened. The
     * signature took material rather than a grade, and the material was composable.
     */
    #[Test]
    public function composedMaterialWouldHaveGrantedTheOnlyGradeThatProvesBehaviour(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): Measurement => Measurement::couldNotRun('the stored evidence chain', 'Implemented.'),
            'A measurement was produced by something that measured nothing.',
        );
    }

    /**
     * The exact signature the previous brief forbade by name: the class as a
     * string, rather than read off an instance.
     */
    #[Test]
    public function aClassNameWouldHaveGrantedAContractDischargedByItsBinding(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): ContractResolution => ContractResolution::answeredBy(
                'Pulsar\Security\Crypto\TokenStoreInterface',
                'holds the tokens that stand in for primary account numbers',
                'Pulsar\Security\Crypto\DatabaseTokenStore',
                ['Pulsar\Security\Crypto\DatabaseTokenStore'],
            ),
            'A contract was reported discharged on a class name the caller wrote down.',
        );
    }

    /**
     * `holds` is `in_array($member, $roster)` — arithmetic over input the caller
     * invented. No signature can tell an enumeration from a literal, so the caller
     * is what has to be checked.
     */
    #[Test]
    public function anInventedRosterWouldHaveGrantedMembershipComputedFromItself(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): Inspection => Inspection::membership(
                'the booted extensions',
                'pulsar/ai-governance',
                ['pulsar/ai-governance'],
                'Implemented.',
            ),
            'Membership was computed over a roster written by the caller.',
        );
    }

    /**
     * The inverse attack, and the more valuable one: retiring a live requirement by
     * declaring the deployment has no subject for it. `[]` is trivial to write.
     */
    #[Test]
    public function anEmptyPopulationWouldHaveRetiredALiveRequirementAsInapplicable(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): SubjectAbsence => SubjectAbsence::noneIn(
                'networked database connections',
                [],
                'This deployment has none.',
            ),
            'A live requirement was retired by declaring its subject absent.',
        );
    }

    /**
     * The gentler half of the same attack: a config read that says the control is
     * on. Harmless on its own now that Declared proves nothing — and still refused,
     * because the report prints it under the name of whoever observed it.
     */
    #[Test]
    public function aConfigReadWouldHaveGrantedTheControlOnAnIntention(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): Observation => Observation::declaredMet(
                ObservationId::DebugModeDisabled,
                'Implemented.',
                self::class,
            ),
            'A configuration read was recorded as a fact by something that read no configuration.',
        );
    }

    /**
     * The one thing an operator assertion is admissible for is carrying a control
     * to NotApplicable, which is why code must not be able to make one.
     */
    #[Test]
    public function anAssertionWouldHaveGrantedAControlItsWayOutOfScope(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): Observation => Observation::assertedOutOfScope(
                ObservationId::ScopeStoresCardholderData,
                'Implemented.',
                self::class,
            ),
            'A control was scoped out on an assertion no operator made.',
        );
    }

    /**
     * Review's objection to {@see ControlFinding::assess()} was exact: it takes the
     * evidence set from its caller with no proof of where it came from. The answer
     * is not to seal the method — it is that there is no longer a fabricated
     * evidence set to hand it.
     */
    #[Test]
    public function aFabricatedEvidenceSetWouldHaveGrantedAFindingItsCallerAimed(): void
    {
        $this->assertRefusedAsForeign(
            static fn(): ControlFinding => ControlFinding::assess(
                self::declaration(),
                ControlEvidence::gathered(...self::everyFactAtGrade(ObservationGrade::Measured, true)),
            ),
            'A finding was assessed against an evidence set the caller composed.',
        );
    }

    /**
     * Reaching the private verdict factories grants nothing either. They are
     * private, which Reflection ignores — so what stops an authored outcome is not
     * the visibility but the check inside: a Satisfied verdict must be handed an
     * observation that is admissible as proof, and there is no way to obtain one.
     */
    #[Test]
    public function reachingThePrivateVerdictFactoryWouldHaveGrantedAnOutcomeWithoutProof(): void
    {
        $satisfied = new ReflectionMethod(ProbeVerdict::class, 'satisfied');

        self::assertTrue($satisfied->isPrivate(), 'ProbeVerdict::satisfied() is callable outright.');

        $this->expectException(InadmissibleEvidenceException::class);

        $satisfied->invokeArgs(null, ['Observed working.', [], [], ControlSubject::CardholderData]);
    }

    /**
     * The same factory, reached the same way, and handed a real MEASURED
     * observation — refused because it is about another estate.
     *
     * This is the A2 rule proved where it is hardest to argue with: not in the
     * decision table, which a second table could be written beside, but on the
     * value itself. The observation offered is admissible in every earlier sense —
     * present, graded Measured, produced by the component that measures — and it
     * interrogated the compliance evidence register while the control regulates
     * the PAN estate.
     */
    #[Test]
    public function reachingThePrivateVerdictFactoryWithAMeasurementOfAnotherEstateIsRefused(): void
    {
        $satisfied = new ReflectionMethod(ProbeVerdict::class, 'satisfied');

        $measuredElsewhere = SyntheticObservation::of(
            ObservationId::AuditChainVerified,
            ObservationGrade::Measured,
            true,
            'the compliance evidence register verifies',
        );

        self::assertTrue(
            $measuredElsewhere->isAdmissibleAsProof(),
            'The fixture must offer a fact that every earlier rule accepts.',
        );

        $this->expectException(InadmissibleEvidenceException::class);

        $satisfied->invokeArgs(null, [
            'Observed working.',
            [$measuredElsewhere],
            [$measuredElsewhere],
            ControlSubject::CardholderData,
        ]);
    }

    /**
     * The refusal names the file it judged and the directory it requires, because
     * the seal is decided on the FILE and not on the namespace — a class declared
     * into `Pulsar\Compliance\Evidence` from an application defeats a namespace
     * check and not this one. An operator who moves a producer to the wrong place
     * has to be able to read why it stopped working.
     */
    #[Test]
    public function theRefusalNamesTheFileItJudgedAndTheDirectoryItRequires(): void
    {
        try {
            (void) ExecutedSubject::passed('the stored evidence chain', 'Implemented.');

            self::fail('A passing subject was composed outside the measuring component.');
        } catch (InadmissibleEvidenceException $refused) {
            self::assertStringContainsString('OutcomeSealTest.php', $refused->getMessage());
            self::assertStringContainsString('src/Compliance/Evidence/', $refused->getMessage());
        }
    }

    // --- The paths that need no factory at all --------------------------------

    /**
     * A private constructor settles who may CALL a value into existence and settles
     * nothing about the ways PHP hands you one without a constructor. Each of the
     * three is a complete bypass, each is attempted here against every value in the
     * directory, and each must refuse.
     *
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('everyValueInTheDirectory')]
    public function noValueCanBeReopenedWithoutItsConstructor(string $class): void
    {
        $value = self::instanceOf($class);

        foreach (self::REOPENING_PATHS as $path) {
            $this->assertRefused(
                static fn(): mixed => match ($path) {
                    // The payload is this test's own value, and the point of the call is that
                    // the class refuses to come back from one at all.
                    // nosemgrep: php.lang.security.unserialize-use.unserialize-use
                    'unserialize' => unserialize(serialize($value)),
                    '__set_state' => new ReflectionMethod($class, '__set_state')->invoke(null, ['id' => 'anything']),
                    default => clone $value,
                },
                sprintf('%s came back through %s, so the seal can be stepped around.', $class, $path),
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function everyValueInTheDirectory(): iterable
    {
        foreach (self::vocabulary() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isEnum() || $reflection->isInterface() || $reflection->isSubclassOf(Throwable::class)) {
                continue;
            }

            if (array_key_exists($reflection->getShortName(), self::SERVICES)) {
                continue;
            }

            // A class with no instance state records nothing and cannot be reopened
            // into anything: MeasuringComponent is the seal itself, all static, and
            // has no properties for a payload to set.
            if ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PRIVATE) === []) {
                continue;
            }

            yield $reflection->getShortName() => [$class];
        }
    }

    // --- The escapes: named, demonstrated, and bounded by a source scan -------

    /**
     * `Closure::bind` reaches the private constructor from any file, and no runtime
     * check in these classes can stop it.
     *
     * THIS IS A FINDING, WRITTEN AS A TEST BECAUSE IT IS TRUE. The expression below
     * is four lines, uses no Reflection, violates no `#[Internal]`, and produces the
     * strongest evidence in the system about a deployment nobody looked at:
     * fifty-one of these compose an evidence set, and that evidence set assesses
     * Satisfied. It is the same defeat as the first three, one language feature
     * further down, and chasing it with a fifth check inside the constructor would
     * not work either —
     * {@see aValueBuiltWithoutItsConstructorIsHeldToNoneOfItsInvariants()} shows the
     * constructor being skipped entirely.
     *
     * What follows from that is the shape of the whole design: the vocabulary
     * cannot be made unforgeable at runtime, so the property that is actually worth
     * defending is that no code in this repository forges one, and that IS
     * defensible — see the two scans below.
     */
    #[Test]
    public function aClosureBoundToTheVocabularyScopeReachesThePrivateConstructor(): void
    {
        $observation = self::bound(Observation::class, [
            ObservationId::AuditChainVerified,
            ObservationGrade::Measured,
            true,
            'Implemented.',
            self::class,
        ]);

        self::assertInstanceOf(Observation::class, $observation);
        self::assertTrue(
            $observation->isAdmissibleAsProof(),
            'The bound-scope escape no longer produces admissible evidence. If a design closed '
                . 'this, say so here and in MeasuringComponent, and turn this test into the '
                . 'refusal it is now able to assert.',
        );
    }

    /**
     * The invariants that were moved into the private constructors bind only a
     * caller that runs the constructor. Nothing forces one to.
     *
     * `newInstanceWithoutConstructor()` and {@see ReflectionProperty::setValue()}
     * produce a {@see Measurement} that names nothing as having run — the exact
     * value {@see Measurement::completed()} refuses and the exact value
     * {@see everyEscapeIsStillHeldToTheInvariantsItRuns()} shows the escape being
     * refused for. Both are true: the invariant holds for a caller who goes through
     * the constructor, and there is no way to make anyone go through it.
     */
    #[Test]
    public function aValueBuiltWithoutItsConstructorIsHeldToNoneOfItsInvariants(): void
    {
        $measurement = new ReflectionClass(Measurement::class)->newInstanceWithoutConstructor();

        foreach (['subject' => 'the stored evidence chain', 'results' => [], 'detail' => 'Implemented.'] as $name => $value) {
            new ReflectionProperty(Measurement::class, $name)->setValue($measurement, $value);
        }

        self::assertSame([], $measurement->results);
        self::assertSame(
            'the stored evidence chain',
            $measurement->subject,
            'A measurement exists that names nothing as having run, which its constructor would '
                . 'have refused. An invariant in a constructor is a bar for whoever calls it.',
        );
    }

    /**
     * No file under `src/` or `extensions/` reaches the vocabulary through
     * Reflection or a bound closure. There is no allow-list for this one and none
     * is needed: nothing that ships does it.
     *
     * This is the enforceable half of the seal. The runtime check decides who may
     * call a factory; this decides who may step around the question, and it is the
     * only one of the two that a language feature cannot walk past. A forged fact
     * has to be written down somewhere, and if it is written down in this
     * repository, this test fails.
     */
    #[Test]
    public function noProductionFilePiercesTheVocabularysScope(): void
    {
        $offenders = [];

        foreach (self::piercingFiles() as $path => $used) {
            if (str_starts_with($path, 'src/') || str_starts_with($path, 'extensions/')) {
                $offenders[$path] = $used;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A file that ships reaches a compliance value without asking its class. Whatever it '
                . 'is doing, it is not measuring a deployment: the component that measures calls '
                . 'the factories, and they answer it.',
        );
    }

    /**
     * Under `tests/`, only the files named in {@see MAY_PIERCE} may bypass a
     * constructor, and each says why.
     *
     * This is the test that answers "can a fixture be built without the escape?".
     * If one could, the escape would be decoration: a fixture that reaches a value
     * some other way is a fabrication route with a test file around it, and the
     * next reviewer would find it before the next release did.
     */
    #[Test]
    public function onlyTheAcknowledgedEscapesBypassAConstructor(): void
    {
        $found = [];

        foreach (self::piercingFiles() as $path => $used) {
            if (str_starts_with($path, 'tests/')) {
                $found[] = $path;
            }
        }

        $allowed = array_keys(self::MAY_PIERCE);
        sort($found);
        sort($allowed);

        self::assertSame(
            $allowed,
            $found,
            'A test file reaches into a class scope near the compliance vocabulary. If it is '
                . 'building a fixture, build it through ReflectedVocabulary, which is the one '
                . 'place a fabrication is meant to be visible; if it is piercing something else '
                . 'entirely, add it to MAY_PIERCE with the reason.',
        );

        foreach (self::MAY_PIERCE as $path => $reason) {
            self::assertNotSame('', $reason, sprintf('The MAY_PIERCE entry for %s states no reason.', $path));
        }
    }

    /**
     * The escape the fixtures use still works, and is asserted to, because a seal
     * nobody can name is a seal nobody can review.
     */
    #[Test]
    public function reflectionThroughTheAcknowledgedEscapeStillReachesTheConstructor(): void
    {
        $observation = SyntheticObservation::of(
            ObservationId::AuditChainVerified,
            ObservationGrade::Measured,
            true,
            'built through the acknowledged escape',
        );

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->isAdmissibleAsProof());
    }

    /**
     * An escape that goes through the constructor meets the constructor's bar: a
     * measurement that named nothing that ran, an inspection over an estate that
     * came back empty, and an absence claimed while subjects are standing there are
     * all refused there rather than in the factory.
     *
     * @param class-string            $class
     * @param list<mixed>             $arguments
     * @param class-string<Throwable> $refusal
     */
    #[Test]
    #[DataProvider('everyVacuousValue')]
    public function everyEscapeIsStillHeldToTheInvariantsItRuns(string $class, array $arguments, string $refusal): void
    {
        $this->expectException($refusal);

        (void) ReflectedVocabulary::construct($class, $arguments);
    }

    /**
     * @return iterable<string, array{class-string, list<mixed>, class-string<Throwable>}>
     */
    public static function everyVacuousValue(): iterable
    {
        yield 'measured, and nothing was measured' => [
            Measurement::class,
            ['the stored evidence chain', [], 'Implemented.', true],
            UnmeasuredSubjectException::class,
        ];

        yield 'every member of no members complies' => [
            Inspection::class,
            ['classified routes', true, 'Implemented.', 0],
            UnmeasuredSubjectException::class,
        ];

        yield 'the platform offers it, and nothing was named' => [
            PlatformCapability::class,
            ['the AEAD primitives libsodium provides', [], true, 'Implemented.', true],
            UnmeasuredSubjectException::class,
        ];

        yield 'no subject, over an estate holding two' => [
            SubjectAbsence::class,
            ['networked database connections', 'This deployment has none.', 2],
            InadmissibleEvidenceException::class,
        ];
    }

    // --- And the outcome still follows the evidence ---------------------------

    /**
     * The seal would be theatre if the outcome still followed the caller. The same
     * declaration, against a deployment that shows nothing, cannot come out
     * Satisfied — and a satisfied finding always carries what satisfied it.
     */
    #[Test]
    public function theSameControlFollowsTheEvidenceRatherThanTheCaller(): void
    {
        $observed = ControlFinding::assess(self::declaration(), self::deployment(ObservationGrade::Measured, true));
        $absent = ControlFinding::assess(self::declaration(), self::deployment(ObservationGrade::Measured, false));

        self::assertSame(ControlOutcome::Satisfied, $observed->outcome);
        self::assertSame(ControlOutcome::Unsatisfied, $absent->outcome);
        self::assertNotSame([], $observed->evidence, 'A satisfied finding must carry what satisfied it.');
    }

    /**
     * And the second rule: resolved identity is not behaviour. Every required fact
     * present, every one of them a resolved class name, and the control is still
     * not satisfied.
     */
    #[Test]
    public function resolvedIdentityAloneCannotSatisfyAControl(): void
    {
        $finding = ControlFinding::assess(self::declaration(), self::deployment(ObservationGrade::Resolved, true));

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString('(resolved)', $finding->summary);
    }

    /**
     * And the third: a platform primitive being installed is not behaviour either.
     *
     * The sibling of {@see resolvedIdentityAloneCannotSatisfyAControl()}, and the
     * one this repository needed most recently. Every required fact present, every
     * one of them a capability the PHP build happens to ship, and the control is
     * still not satisfied — because the same answer comes back on a deployment
     * that uses the primitive and on one that never touches it, and a fact that
     * cannot tell those two apart cannot decide a control about either.
     */
    #[Test]
    public function platformAvailabilityAloneCannotSatisfyAControl(): void
    {
        $finding = ControlFinding::assess(self::declaration(), self::deployment(ObservationGrade::Available, true));

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString('(available)', $finding->summary);
    }

    // --- Machinery ------------------------------------------------------------

    /**
     * Attacks on the doors classified COMPUTED, keyed by door.
     *
     * @return array<string, Closure(): void>
     */
    private static function attacksOnComputedDoors(): array
    {
        return [
            'ControlAssessment::summarize' => static function (): void {
                self::assertClaimsNothing(ControlAssessment::summarize([]));
            },
            'ControlFinding::assess' => static function (): void {
                $finding = ControlFinding::assess(
                    self::declaration(),
                    self::deployment(ObservationGrade::Measured, false),
                );

                self::assertNotSame(
                    ControlOutcome::Satisfied,
                    $finding->outcome,
                    'A deployment that shows nothing assessed as satisfied.',
                );
                self::assertNotSame(
                    ControlOutcome::Partial,
                    $finding->outcome,
                    'A deployment that shows nothing assessed as partly observed.',
                );
            },
            'CoverageSummary::over' => static function (): void {
                self::assertClaimsNothing(CoverageSummary::over([]));
            },
            'ProbeVerdict::reach' => static function (): void {
                $verdict = ProbeVerdict::reach(
                    new PanAtRestProbe()->requirement(),
                    self::deployment(ObservationGrade::Measured, false),
                    ControlSubject::CardholderData,
                );

                self::assertNotSame(
                    ControlOutcome::Satisfied,
                    $verdict->outcome,
                    'A deployment that shows nothing reached a satisfied verdict.',
                );
            },
        ];
    }

    /**
     * A tally over no findings claims no coverage.
     *
     * `hasGaps()` is false here, and that is counting rather than a clean bill: the
     * guarantee that a report is never assembled from an empty finding set belongs
     * to the code that assembles reports, and `compliance:report` refuses an empty
     * framework list for exactly this reason.
     */
    private static function assertClaimsNothing(CoverageSummary $summary): void
    {
        self::assertSame(0, $summary->assessed, 'A summary over no findings claims controls were assessed.');
        self::assertSame(0, $summary->satisfied, 'A summary over no findings claims a satisfied control.');
        self::assertSame(0.0, $summary->probedCoveragePercent(), 'Counting nothing produced a coverage figure.');
    }

    /**
     * The classes in `src/Compliance/Control`, from the directory.
     *
     * @return list<class-string>
     */
    private static function vocabulary(): array
    {
        $files = glob(self::packageRoot() . self::VOCABULARY . '*.php');
        $classes = [];

        if ($files === false) {
            return $classes;
        }

        foreach ($files as $file) {
            /** @var class-string $class */
            $class = self::VOCABULARY_NAMESPACE . substr(basename($file), 0, -4);

            if (new ReflectionClass($class)->isTrait()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Every public static method in the directory that hands back a vocabulary
     * type, mapped to the type it hands back.
     *
     * Exceptions are excluded: their named constructors return themselves, and
     * {@see noExceptionHandsBackAVocabularyValue()} is what holds them to being
     * diagnostics rather than facts.
     *
     * @return array<string, class-string>
     */
    private static function doorsInTheDirectory(): array
    {
        $doors = [];

        foreach (self::vocabulary() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isEnum() || $reflection->isInterface() || $reflection->isSubclassOf(Throwable::class)) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC) as $method) {
                if (!$method->isPublic() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }

                $returns = self::vocabularyTypeReturnedBy($method, $class);

                if ($returns === null) {
                    continue;
                }

                $doors[$reflection->getShortName() . '::' . $method->getName()] = $returns;
            }
        }

        ksort($doors);

        return $doors;
    }

    /**
     * The vocabulary type a method hands back, or null if it hands back something
     * else.
     *
     * @param class-string $declaring
     *
     * @return class-string|null
     */
    private static function vocabularyTypeReturnedBy(ReflectionMethod $method, string $declaring): ?string
    {
        $type = $method->getReturnType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();

        if ($name === 'self' || $name === 'static') {
            return $declaring;
        }

        if (!str_starts_with($name, self::VOCABULARY_NAMESPACE)) {
            return null;
        }

        /** @var class-string $name */
        return $name;
    }

    private static function returnsSomethingAboutADeployment(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        // `array` is on the list because the findings a service hands back are one:
        // a return type of `list<ControlFinding>` is spelled `array` at runtime.
        return $type->getName() === 'array' || str_starts_with($type->getName(), self::VOCABULARY_NAMESPACE);
    }

    private static function takesASealedValue(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            if (in_array($type->getName(), self::FACT_BEARING, true)) {
                return true;
            }
        }

        return false;
    }

    private static function door(string $door): ReflectionMethod
    {
        [$class, $method] = explode('::', $door);

        /** @var class-string $qualified */
        $qualified = self::VOCABULARY_NAMESPACE . $class;

        return new ReflectionMethod($qualified, $method);
    }

    /**
     * Arguments for a door, synthesized from its own signature.
     *
     * The material is the best a caller could bring: a real {@see Measurement},
     * a real {@see Inspection}. A door that wants a type this cannot produce fails
     * loudly rather than being skipped, because a skipped attack is how the last
     * three versions of this file stayed green.
     *
     * @return list<mixed>
     */
    private static function materialFor(ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $arguments[] = self::material($parameter);
        }

        return $arguments;
    }

    private static function material(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

        return match ($name) {
            'string' => 'Implemented.',
            'array' => [],
            'bool' => true,
            'int' => 0,
            ObservationId::class => ObservationId::AuditChainVerified,
            ObservationGrade::class => ObservationGrade::Measured,
            Observation::class => self::instanceOf(Observation::class),
            Measurement::class => self::instanceOf(Measurement::class),
            ContractResolution::class => self::instanceOf(ContractResolution::class),
            Inspection::class => self::instanceOf(Inspection::class),
            PlatformCapability::class => self::instanceOf(PlatformCapability::class),
            SubjectAbsence::class => self::instanceOf(SubjectAbsence::class),
            default => self::fail(sprintf(
                'The attack cannot bring a %s for %s::$%s. Teach it how, so the door is tried '
                    . 'rather than skipped.',
                $name,
                $parameter->getDeclaringFunction()->getName(),
                $parameter->getName(),
            )),
        };
    }

    /**
     * One instance of any value in the directory.
     *
     * Everything a factory refuses is built through the acknowledged escape;
     * everything a caller may legitimately build is built the legitimate way, so
     * the reopening attacks run against values in the state real ones are in.
     *
     * @param class-string $class
     */
    private static function instanceOf(string $class): object
    {
        return match ($class) {
            ContractResolution::class => ReflectedVocabulary::construct(
                ContractResolution::class,
                ['a contract', 'a role', null, [], []],
            ),
            ControlDeclaration::class => self::declaration(),
            ControlEvidence::class => self::deployment(ObservationGrade::Measured, true),
            ControlFinding::class => ControlFinding::assess(
                self::declaration(),
                self::deployment(ObservationGrade::Measured, true),
            ),
            ControlRequirement::class => new PanAtRestProbe()->requirement(),
            CoverageSummary::class => CoverageSummary::over([]),
            ExecutedSubject::class => ReflectedVocabulary::construct(
                ExecutedSubject::class,
                ['a subject', true, 'a fact under test'],
            ),
            Inspection::class => ReflectedVocabulary::construct(
                Inspection::class,
                ['a population', true, 'a fact under test', null],
            ),
            Measurement::class => ReflectedVocabulary::construct(
                Measurement::class,
                ['a subject', [], 'a fact under test', false],
            ),
            PlatformCapability::class => ReflectedVocabulary::construct(
                PlatformCapability::class,
                ['a capability', [], false, 'a fact under test', false],
            ),
            Observation::class => SyntheticObservation::of(
                ObservationId::AuditChainVerified,
                ObservationGrade::Measured,
                true,
                'a fact under test',
            ),
            ProbeVerdict::class => ProbeVerdict::reach(
                new PanAtRestProbe()->requirement(),
                self::deployment(ObservationGrade::Measured, true),
                ControlSubject::CardholderData,
            ),
            RequiredFact::class => RequiredFact::essential(
                ObservationId::MasterKeyResolved,
                ['Bind a master key.'],
            ),
            SubjectAbsence::class => ReflectedVocabulary::construct(
                SubjectAbsence::class,
                ['a subject', 'a fact under test', 0],
            ),
            default => self::fail(sprintf(
                'The seal test has no instance of %s, so the reopening paths are not attacked '
                    . 'against it. Add one.',
                $class,
            )),
        };
    }

    /**
     * Build a value by binding a closure to its class scope — the escape that needs
     * no Reflection.
     *
     * The class is taken as a runtime string rather than written out, which is also
     * the point: a static analyser sees `new $type(...)` and has nothing to say
     * about a private constructor it cannot resolve.
     *
     * @param class-string $class
     * @param list<mixed>  $arguments
     */
    private static function bound(string $class, array $arguments): object
    {
        $mint = Closure::bind(
            static fn(string $type, array $args): object => new $type(...$args),
            null,
            $class,
        );

        $value = $mint($class, $arguments);

        self::assertIsObject($value, sprintf('A closure bound to %s produced no value.', $class));

        return $value;
    }

    /**
     * Files that name the vocabulary and reach into a class scope, path => what
     * they use.
     *
     * The whole tree is read once and cached: the question is where an attack COULD
     * be written, and answering it by looking only where one is expected is how a
     * scan comes back clean while the code is not.
     *
     * @return array<string, list<string>>
     */
    private static function piercingFiles(): array
    {
        if (self::$piercingFiles !== null) {
            return self::$piercingFiles;
        }

        $root = self::packageRoot();
        $found = [];

        foreach (['src', 'extensions', 'tests'] as $tree) {
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $tree, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($walk as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if ($source === false || !str_contains($source, self::NAMES_THE_VOCABULARY)) {
                    continue;
                }

                $used = self::piercingIn($source);

                if ($used === []) {
                    continue;
                }

                $path = str_replace($root, '', str_replace('\\', '/', $file->getPathname()));
                $found[$path] = $used;
            }
        }

        ksort($found);
        self::$piercingFiles = $found;

        return $found;
    }

    /**
     * Which piercing constructs a file uses, in code rather than in prose.
     *
     * Comments are stripped first: this file, {@see MeasuringComponent} and
     * {@see ReflectedVocabulary} all discuss the escapes at length, and a scan that
     * counted the discussion would force everyone to stop writing the discussion
     * down.
     *
     * @return list<string>
     */
    private static function piercingIn(string $source): array
    {
        $code = '';

        foreach (PhpToken::tokenize($source) as $token) {
            $code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? ' ' : $token->text;
        }

        $used = [];

        foreach (self::PIERCING as $construct) {
            if (str_contains($code, $construct)) {
                $used[] = $construct;
            }
        }

        return $used;
    }

    /**
     * The package root, with a trailing slash and forward separators.
     */
    private static function packageRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 4)) . '/';
    }

    private static function declaration(): ControlDeclaration
    {
        return ControlDeclaration::probed(
            id: 'Req3.4',
            framework: ComplianceFramework::PciDss,
            title: 'Render PAN Unreadable Anywhere It Is Stored',
            requirement: 'Render primary account numbers unreadable anywhere they are stored.',
            probe: new PanAtRestProbe(),
            subject: ControlSubject::CardholderData,
        );
    }

    /**
     * A whole deployment at one grade, through the acknowledged escape.
     */
    private static function deployment(ObservationGrade $grade, bool $present): ControlEvidence
    {
        return ReflectedVocabulary::evidence(...self::everyFactAtGrade($grade, $present));
    }

    /**
     * @return list<Observation>
     */
    private static function everyFactAtGrade(ObservationGrade $grade, bool $present): array
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $isScope = str_starts_with($id->value, 'scope_');

            $observations[] = SyntheticObservation::of(
                $id,
                $isScope ? ObservationGrade::Asserted : $grade,
                $isScope || $present,
                sprintf('seal test: %s', $id->value),
            );
        }

        return $observations;
    }

    /**
     * The attempt was refused, and refused BY THE SEAL rather than by some
     * validation it happened to trip on the way.
     *
     * The distinction matters: `SubjectAbsence::noneIn('...', [], '...')` over an
     * empty population is a well-formed value and would be accepted on its merits,
     * so a test satisfied by any refusal would keep passing if the seal were
     * removed and only the invariant left.
     *
     * @param callable(): mixed $attempt
     */
    private function assertRefusedAsForeign(callable $attempt, string $wouldHaveGranted): void
    {
        $this->assertRefused($attempt, $wouldHaveGranted, 'is not the component that measures');
    }

    /**
     * @param callable(): mixed $attempt
     */
    private function assertRefused(callable $attempt, string $wouldHaveGranted, string $because = ''): void
    {
        try {
            $attempt();
        } catch (InadmissibleEvidenceException $refused) {
            self::assertNotSame('', $refused->getMessage(), 'A refusal must say what it refused.');

            if ($because !== '') {
                self::assertStringContainsString($because, $refused->getMessage(), $wouldHaveGranted);
            }

            return;
        }

        self::fail($wouldHaveGranted);
    }
}
