<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use Pulsar\Api\Internal;

use function debug_backtrace;
use function is_string;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * The one place that decides who is allowed to produce a compliance fact.
 *
 * WHY THIS CLASS EXISTS AT ALL, stated first because it is an admission. PHP has
 * no package-private visibility. A member is private to a CLASS or protected to a
 * HIERARCHY, and there is no third option — so "only the component that measures
 * may construct an Observation" is not expressible in the type system, no matter
 * how the signatures are arranged. Three previous attempts arranged signatures.
 * Each was defeated by composing the public factories that had to remain public
 * for the gatherer to reach them, because the gatherer is a different class in a
 * different namespace and PHP gives it no other way in.
 *
 * So the rule is enforced where PHP does have an answer: the identity of the code
 * that is calling. Every production seam in the vocabulary asks this class
 * whether its caller is the measuring component, and the answer is decided by the
 * FILE the calling class is compiled from, not by its name and not by its
 * namespace. `src/Compliance/Evidence/` is the measuring component; nothing else
 * in this repository, in an extension, in an application or in a test can produce
 * a fact, because nothing else is compiled from those files.
 *
 * WHAT THIS DOES AND DOES NOT STOP, precisely:
 *
 *  - It stops every composition of public factories. `Observation::measured(...)`,
 *    `ExecutedSubject::passed(...)`, `ContractResolution::answeredBy(...)` and the
 *    rest all throw when the caller is not the gatherer, whatever they are handed.
 *    That is the attack all three earlier designs fell to, and it is closed
 *    outright rather than narrowed.
 *  - It does not stop Reflection, and it is not meant to. Every constructor in
 *    the vocabulary is private, and `ReflectionClass::newInstanceWithoutConstructor()`
 *    followed by an explicit constructor invocation will always reach one. That
 *    escape is deliberate and it is the one the tests use: a fixture that needs a
 *    Measured observation about a deployment nobody ran has to say so in the
 *    shape of its own code. Reflection is a statement that you are stepping
 *    outside the supported API; a factory call is a statement that you are inside
 *    it, and only the second one is available to code that is not measuring.
 *  - It does not stop someone editing the files in `src/Compliance/Evidence/`.
 *    Nothing can, and nothing should try: those files ARE the component that
 *    measures, and their reviewability is the whole security property. What the
 *    file check buys over a namespace check is that a class DECLARED into
 *    `Pulsar\Compliance\Evidence` from an application or an extension is still
 *    refused, because it is not compiled from this directory.
 *
 * The cost is one small backtrace per fact — roughly fifty per compliance report,
 * against a run that already opens a database session, executes every registered
 * health check and recomputes an HMAC per stored evidence record.
 */
#[Internal(reason: 'The production seal; called only from the vocabulary it seals')]
final class MeasuringComponent
{
    /**
     * The directory whose files ARE the component that measures the deployment.
     *
     * Resolved from this file rather than configured, so it cannot be widened by
     * a setting: the seal and the thing it seals ship in the same commit.
     */
    private const string MEASURES = 'src/Compliance/Evidence/';

    /** The vocabulary's own directory; frames inside it are the seal calling itself. */
    private const string VOCABULARY = 'src/Compliance/Control/';

    /** How far out to walk before giving up. Production chains here are two frames deep. */
    private const int FRAMES = 8;

    /**
     * All static; there is nothing to hold.
     */
    private function __construct() {}

    /**
     * Refuse unless the code calling this seam is the component that measures.
     *
     * @param class-string $produced The vocabulary type whose production is being attempted
     * @param string       $seam     The factory that was called, for the message
     *
     * @throws InadmissibleEvidenceException when the caller is anything else
     */
    public static function assertProducing(string $produced, string $seam): void
    {
        $caller = self::callingFile();

        if ($caller !== null && str_starts_with($caller, self::directory(self::MEASURES))) {
            return;
        }

        throw InadmissibleEvidenceException::forForeignProduction($produced, $seam, $caller);
    }

    /**
     * The file that called the seam, skipping the vocabulary's own frames.
     *
     * A frame's `file` is where the CALL was written, so frame one is the file
     * that called the factory. Frames inside the vocabulary directory are skipped
     * because a factory delegating to another factory is the vocabulary talking to
     * itself; the question is always who spoke first from outside it.
     */
    private static function callingFile(): ?string
    {
        $vocabulary = self::directory(self::VOCABULARY);

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::FRAMES) as $index => $frame) {
            if ($index === 0) {
                continue;
            }

            $file = $frame['file'] ?? null;

            if (!is_string($file)) {
                continue;
            }

            $normalised = str_replace('\\', '/', $file);

            if (str_starts_with($normalised, $vocabulary)) {
                continue;
            }

            return $normalised;
        }

        return null;
    }

    /**
     * An absolute, separator-normalised directory under the package root.
     *
     * The root is derived by removing this file's own known suffix from
     * {@see __DIR__} rather than by walking up with `..`, because a path holding
     * `..` never matches a compiled file path by prefix and would silently refuse
     * the gatherer along with everyone else.
     */
    private static function directory(string $suffix): string
    {
        $here = str_replace('\\', '/', __DIR__);
        $root = substr($here, 0, -strlen(self::VOCABULARY) + 1);

        return $root . $suffix;
    }
}
