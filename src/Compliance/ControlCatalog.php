<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ControlDeclaration;
use Throwable;

use function array_values;
use function count;
use function in_array;
use function sprintf;

/**
 * The registry of declared controls.
 *
 * It holds {@see ControlDeclaration}s and offers no view by outcome — no
 * `byStatus()`, no coverage arithmetic — because a catalog cannot know an
 * outcome. An outcome exists only once a probe has run against a gathered
 * evidence set, which is {@see \Pulsar\Compliance\Control\ControlAssessment}'s
 * job. Separating the two is what makes a control status impossible to write as
 * a literal: the catalog holds mappings that have no status to write, and the
 * statuses are computed later, from the deployment.
 *
 * Entries are keyed by framework AND identifier. Control identifiers are only
 * unique within a standard ("A.5.1" belongs to ISO 27001 and "6.1.2" to ISO
 * 42001, and SOC 2 and SWIFT CSP both number theirs from 1), so a single flat
 * index would let one standard's control silently displace another's.
 *
 * DECLARATIONS ARE BUILT ON FIRST READ, NOT AT BOOT
 * -------------------------------------------------
 * Nothing on the request path reads this catalog. It is read by
 * `compliance:report` and by the `compliance:check` gate, and by nothing else —
 * a control declaration is an input to an assessment, and an assessment only
 * happens when someone asks for one. Building it at boot therefore charged every
 * request of every application for a data structure that request would never
 * look at: every mapping class autoloaded and every declaration plus its probe
 * constructed, measured at 0.52 ms warm and 21.7 ms cold per boot — the eighth
 * most expensive of the framework's 51 wirings. That reading was taken when the
 * catalog held 193 declarations from sixteen mappings; it holds 215 from
 * seventeen now, so the figure is a floor rather than a current measurement, and
 * it is left as measured rather than rescaled by arithmetic nobody ran. The
 * conditions are recorded in {@see \Pulsar\Core\Wiring\ComplianceCatalogWiring}.
 *
 * {@see contribute()} therefore takes a SOURCE — a callable that produces
 * declarations — and the sources are executed at the first read. The mapping
 * classes are not even autoloaded until then.
 *
 * The laziness is memoized, not repeated: each source runs exactly once, so two
 * reads cannot disagree, and a report never observes a half-built catalog.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class ControlCatalog
{
    /** @var array<string, ControlDeclaration> */
    private array $declarations = [];

    /**
     * Sources not yet executed. Emptied by the first read.
     *
     * @var list<callable(): list<ControlDeclaration>>
     */
    private array $sources = [];

    /** Whether any read has happened, and with it the deferred sources. */
    private bool $built = false;

    /**
     * The failure that ended the build, if it ended in one.
     *
     * Re-thrown on every later read. A build that threw half-way leaves some
     * sources' declarations registered and the rest absent, and a catalog missing
     * a control looks exactly like a catalog whose controls are all present — so
     * a partially built catalog must never be readable, not even once.
     */
    private ?Throwable $buildFailure = null;

    /**
     * Register a deferred source of declarations.
     *
     * The callable is NOT invoked here. It is invoked once, at the catalog's
     * first read, which is what keeps the declaring mapping classes off the boot
     * path entirely.
     *
     * Contributing after the catalog has been read is refused
     * ({@see CatalogAlreadyBuiltException}) rather than accepted late: a source
     * that arrives after an assessment has run declares controls that assessment
     * silently omitted, and an artefact missing a control looks exactly like an
     * artefact whose controls are all present.
     *
     * {@see register()} carries no such hazard and is deliberately not guarded
     * the same way: it takes declarations rather than a promise of them, so a
     * late call is visible in {@see count()} the instant it happens.
     *
     * @param callable(): list<ControlDeclaration> $source
     *
     * @throws CatalogAlreadyBuiltException when the catalog has already been read
     */
    public function contribute(callable $source): void
    {
        if ($this->built) {
            throw CatalogAlreadyBuiltException::forLateContribution();
        }

        $this->sources[] = $source;
    }

    /**
     * Register declarations.
     *
     * Re-declaring the same control of the same framework is refused rather than
     * silently replacing it: two mappings disagreeing about one control is a bug
     * whose only symptom, under replacement, is that whichever ran last wins.
     *
     * @throws DuplicateControlException when a control is declared twice
     */
    public function register(ControlDeclaration ...$declarations): void
    {
        foreach ($declarations as $declaration) {
            $key = self::key($declaration->framework, $declaration->id);

            if (isset($this->declarations[$key])) {
                throw DuplicateControlException::forControl($declaration->framework, $declaration->id);
            }

            $this->declarations[$key] = $declaration;
        }
    }

    /**
     * Retrieve one control of one framework.
     */
    #[NoDiscard]
    public function get(ComplianceFramework $framework, string $id): ?ControlDeclaration
    {
        $this->build();

        return $this->declarations[self::key($framework, $id)] ?? null;
    }

    #[NoDiscard]
    public function has(ComplianceFramework $framework, string $id): bool
    {
        $this->build();

        return isset($this->declarations[self::key($framework, $id)]);
    }

    /**
     * Every registered declaration, in registration order.
     *
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public function all(): array
    {
        $this->build();

        return array_values($this->declarations);
    }

    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public function byFramework(ComplianceFramework $framework): array
    {
        $this->build();

        $matching = [];

        foreach ($this->declarations as $declaration) {
            if ($declaration->framework === $framework) {
                $matching[] = $declaration;
            }
        }

        return $matching;
    }

    /**
     * The frameworks that have at least one declared control, in registration order.
     *
     * @return list<ComplianceFramework>
     */
    #[NoDiscard]
    public function frameworks(): array
    {
        $this->build();

        $frameworks = [];

        foreach ($this->declarations as $declaration) {
            if (!in_array($declaration->framework, $frameworks, true)) {
                $frameworks[] = $declaration->framework;
            }
        }

        return $frameworks;
    }

    #[NoDiscard]
    public function count(): int
    {
        $this->build();

        return count($this->declarations);
    }

    /**
     * Execute the deferred sources, once.
     *
     * Sources are taken off the pending list BEFORE they run, so a source that
     * throws is never retried with half its declarations already registered — the
     * retry would rediscover them as duplicates and report the wrong defect. The
     * failure itself is remembered and re-thrown on every later read, because the
     * catalog is now missing controls and a caller that swallowed the first
     * exception would be handed a clean-looking partial one.
     *
     * @throws DuplicateControlException when two sources declare the same control
     */
    private function build(): void
    {
        if ($this->buildFailure !== null) {
            throw $this->buildFailure;
        }

        if ($this->built) {
            return;
        }

        $this->built = true;
        $sources = $this->sources;
        $this->sources = [];

        try {
            foreach ($sources as $source) {
                $this->register(...$source());
            }
        } catch (Throwable $failure) {
            $this->buildFailure = $failure;

            throw $failure;
        }
    }

    private static function key(ComplianceFramework $framework, string $id): string
    {
        return sprintf('%s/%s', $framework->value, $id);
    }
}
