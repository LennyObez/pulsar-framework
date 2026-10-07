<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use NoDiscard;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use ReflectionClass;
use RuntimeException;

use function array_values;

/**
 * Builds compliance values through Reflection, which is an ACKNOWLEDGED ESCAPE
 * from the vocabulary's seal and not a supported way to produce evidence.
 *
 * Every constructor in `src/Compliance/Control` is private and every named
 * constructor refuses a caller that is not compiled from
 * `src/Compliance/Evidence` — see {@see \Pulsar\Compliance\Control\MeasuringComponent}.
 * A test cannot therefore describe a deployment by calling the API, and that is
 * the point of the seal rather than an obstacle to work around quietly: if a
 * fixture could be built without Reflection, so could a fabricated compliance
 * report, and the door would still be open.
 *
 * So the escape is concentrated here, in one file, using the one mechanism that
 * announces itself. Code that calls this class is visibly stepping outside the
 * supported API; code that calls a factory is visibly inside it, and only the
 * measuring component can do that. The tests that hold the subsystem to its rules
 * need to describe deployments nobody ran — a deployment where every fact is a
 * config read, one where every fact is a resolved class name, one where nothing
 * holds at all — and there is no honest way to obtain those from a real gatherer.
 *
 * Nothing here is available to production code: `tests/` is not autoloaded into a
 * running application, and a class that reached for this would be as visible in
 * review as the word Reflection makes it.
 */
final readonly class ReflectedVocabulary
{
    /**
     * One observation at a chosen grade and presence.
     *
     * @param non-empty-string $detail
     * @param class-string     $observedBy
     */
    #[NoDiscard]
    public static function observation(
        ObservationId $id,
        ObservationGrade $grade,
        bool $present,
        string $detail,
        string $observedBy = self::class,
        bool $subjectExists = true,
    ): Observation {
        /** @var Observation $observation */
        $observation = self::build(Observation::class, [
            $id,
            $grade,
            $present && $subjectExists,
            $detail,
            $observedBy,
            $subjectExists,
        ]);

        return $observation;
    }

    /**
     * A whole evidence set.
     *
     * Totality is still enforced: the constructor this reaches is the one that
     * refuses an incomplete set, so a fixture may say what it likes about a
     * deployment but may not leave a fact out and let a probe conclude from the
     * hole.
     */
    #[NoDiscard]
    public static function evidence(Observation ...$observations): ControlEvidence
    {
        /** @var ControlEvidence $evidence */
        $evidence = self::build(ControlEvidence::class, array_values($observations));

        return $evidence;
    }

    /**
     * Any vocabulary value, by reaching its private constructor directly.
     *
     * Used by the seal test to prove that the invariants which used to live in the
     * sealed factories — a measurement that named nothing that ran, an inspection
     * over an empty population, an absence claimed over a populated estate — are
     * properties of the VALUE and refuse even the escape.
     *
     * @param class-string $class
     * @param list<mixed>  $arguments
     */
    #[NoDiscard]
    public static function construct(string $class, array $arguments): object
    {
        return self::build($class, $arguments);
    }

    /**
     * @param class-string     $class
     * @param list<mixed>      $arguments
     */
    private static function build(string $class, array $arguments): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new RuntimeException($class . ' has no constructor to reach.');
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invokeArgs($instance, $arguments);

        return $instance;
    }
}
