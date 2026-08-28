<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use FilesystemIterator;
use JsonException;
use Pulsar\Tests\Unit\Integrity\Support\GateCoverageIndex;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function sort;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Which gates this repository has, derived rather than typed.
 *
 * ## Why derived
 *
 * A hand-maintained list of gates is the audited defect one level up. The nine gates that
 * could not fail were each reported as implemented on the strength of code existing; a
 * typed inventory would be reported as complete on the strength of the list existing, and
 * it would be wrong in the one direction that matters -- silently, and always towards
 * "everything is covered". This repository has already been bitten twice by that exact
 * shape: a migration validator carrying a hardcoded provider list, and an allow-list test
 * that passed vacuously on any entry naming a type that did not exist.
 *
 * So nothing here is typed. Every gate is read out of a declaration the repository already
 * maintains for another reason, which means it cannot be forgotten without also breaking
 * the thing that declaration is for:
 *
 *   1. **The leaves of `composer qa`.** composer.json is the single definition of the
 *      local gate -- tools/ci/assert-qa-ci-parity.php already asserts CI runs all of them
 *      -- so its leaves are gates by construction. The expansion follows `@` references
 *      exactly as that script does, because `qa` lists `@boundary:check`, which is itself
 *      a list, and comparing at the top level would report a match that is not one.
 *
 *   2. **First-party scripts invoked by a workflow step that can fail the build.** Read
 *      out of the `run:` blocks of the files in .github/workflows. A step carrying
 *      `continue-on-error: true` is excluded and recorded as excluded: it cannot refuse,
 *      so there is no refusal for a negative test to observe.
 *
 *   3. **Siblings of those scripts that can refuse.** The sweep is what catches the
 *      orphans -- scripts/boundary_ratchet.php, scripts/generate_sbom.php and scripts/qa
 *      are gates by intent that no workflow and no composer script runs, so neither of the
 *      first two sources sees them. A file is swept in when it contains a non-zero exit,
 *      which is the difference between a checker and a worker: {@see canRefuse()}.
 *      tools/bench/worker.php has no verdict to give and is left out by that test rather
 *      than by being named.
 *
 * Note what (3) buys. The directories scanned are not configured -- they are wherever (1)
 * and (2) found something. A gate added next month in a directory nobody has thought of is
 * discovered through its own invocation, and its neighbours are then swept in with it.
 *
 * ## Why comments are stripped first
 *
 * qa_parity_strip_comments() exists in the parity script because its first version read a
 * comment explaining that a step was "annotated rather than run as composer
 * boundary:custom" as proof the command ran. The same trap is live here and is not
 * hypothetical: ci.yml carries the line "This used to be
 * tools/ci/check-version-consistency.sh", naming a file that has since been deleted. A
 * scan that read comments would report a deleted script as an invoked gate, and then
 * demand a negative test for something that does not exist. Comments go first.
 *
 * ## What counts as coverage
 *
 * A real #[GuardsGate(gate: '...')] attribute on a test. Real means the token scanner in
 * {@see GateCoverageIndex::guardedGates()} -- reused rather than rewritten, because the
 * question "does prose count as a declaration" deserves exactly one answer in this
 * repository, and that class already learned the answer the hard way.
 */
final readonly class GateInventory
{
    /** The composer script whose leaves define the local gate. */
    private const string ROOT_SCRIPT = 'qa';

    /** pnpm subcommands that provision rather than judge. */
    private const array PNPM_BUILTINS = ['install', 'exec', 'dlx', 'add', 'run'];

    /** The console entrypoint: one file, several unrelated gates. */
    private const string CONSOLE = 'bin/pulsar';

    /** The gate runner CONTRIBUTING.md points contributors at. */
    private const string LOCAL_ENTRYPOINT = 'scripts/qa';

    public function __construct(private string $root) {}

    /**
     * Every gate, canonicalised, with the aliases each answers to.
     *
     * @return list<Gate>
     */
    public function gates(): array
    {
        /** @var array<string, list<string>> $aliasesByPath */
        $aliasesByPath = [];
        /** @var array<string, list<string>> $whereByPath */
        $whereByPath = [];
        /** @var array<string, string> $standalone */
        $standalone = [];

        foreach ($this->qaLeafScripts() as $leaf => $path) {
            if ($path === null) {
                $standalone['composer ' . $leaf] = 'a leaf of `composer ' . self::ROOT_SCRIPT
                    . '` running a tool rather than a first-party script';

                continue;
            }

            $aliasesByPath[$path][] = 'composer ' . $leaf;
            $whereByPath[$path][] = 'composer ' . self::ROOT_SCRIPT;
        }

        foreach ($this->scriptsInvokedByBlockingSteps() as $path => $workflows) {
            $aliasesByPath[$path] ??= [];
            $whereByPath[$path] = [...($whereByPath[$path] ?? []), ...$workflows];
        }

        foreach ($this->orphanedCheckers() as $path) {
            $aliasesByPath[$path] ??= [];
            $whereByPath[$path] ??= [];
        }

        foreach ($this->composerAliases() as $name => $identity) {
            if (isset($aliasesByPath[$identity])) {
                $aliasesByPath[$identity][] = $name;

                continue;
            }

            // A checker that only composer knows about. `composer extension:coverage` and
            // `composer wiring:check` are the live examples: gates by intent, leaves of
            // nothing, invoked by no workflow. Without this branch they are absent from
            // the inventory entirely -- which would report the repository as fully covered
            // by not counting the gates nothing runs.
            if (self::canRefuse((string) @file_get_contents($this->root . '/' . self::fileOf($identity)))) {
                $aliasesByPath[$identity] = [$name];
                $whereByPath[$identity] = [];
            }
        }

        foreach ($this->composerGatesRunByWorkflows() as $name => $where) {
            $standalone[$name] = $where;
        }

        foreach ($this->pnpmGates() as $name => $where) {
            $standalone[$name] = $where;
        }

        foreach ($this->analyzerRules() as $name => $where) {
            $standalone[$name] = $where;
        }

        foreach ($this->baselineRatchets() as $name => $where) {
            $standalone[$name] = $where;
        }

        $gates = [];

        foreach ($aliasesByPath as $path => $aliases) {
            $where = array_values(array_unique($whereByPath[$path] ?? []));

            $gates[] = new Gate(
                id: $path,
                aliases: array_values(array_unique($aliases)),
                origin: $where === []
                    ? 'a first-party checker that no workflow step and no `composer '
                        . self::ROOT_SCRIPT . '` leaf runs'
                    : 'run by ' . implode(', ', $where),
            );
        }

        foreach ($standalone as $name => $origin) {
            $gates[] = new Gate(id: $name, aliases: [], origin: $origin);
        }

        return $this->sorted($gates);
    }

    /**
     * Each leaf of `composer qa`, mapped to the first-party script it runs, or null when
     * it runs a tool such as phpstan.
     *
     * @return array<string, string|null>
     */
    public function qaLeafScripts(): array
    {
        $scripts = $this->composerScripts();
        $leaves = [];

        foreach ($this->qaLeaves() as $leaf) {
            $body = $scripts[$leaf] ?? '';
            $leaves[$leaf] = is_string($body) ? $this->scriptInvokedBy($body) : null;
        }

        return $leaves;
    }

    /**
     * The composer scripts `qa` ultimately runs, expanded through `@` references.
     *
     * @param list<string> $seen guards a script that references itself
     *
     * @return list<string>
     */
    public function qaLeaves(string $name = self::ROOT_SCRIPT, array $seen = []): array
    {
        if (in_array($name, $seen, true)) {
            return [];
        }

        $definition = $this->composerScripts()[$name] ?? null;

        if (!is_array($definition)) {
            return [$name];
        }

        $seen[] = $name;
        $leaves = [];

        foreach ($definition as $entry) {
            $leaves = str_starts_with($entry, '@')
                ? [...$leaves, ...$this->qaLeaves(substr($entry, 1), $seen)]
                : [...$leaves, $entry];
        }

        return array_values(array_unique($leaves));
    }

    /**
     * composer.json's `scripts` block, as declared.
     *
     * @return array<string, string|list<string>>
     */
    public function composerScripts(): array
    {
        $raw = @file_get_contents($this->root . '/composer.json');

        if (!is_string($raw)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $scripts = $composer['scripts'] ?? null;

        if (!is_array($scripts)) {
            return [];
        }

        $declared = [];

        foreach ($scripts as $name => $body) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($body)) {
                $declared[$name] = $body;

                continue;
            }

            if (is_array($body)) {
                $declared[$name] = array_values(array_filter($body, is_string(...)));
            }
        }

        return $declared;
    }

    /**
     * Every named step of every workflow, with whether a failure there stops the build.
     *
     * A text walk rather than a YAML parse, for the reason the parity script gives: a
     * `run:` block is shell, and what is being read is what the shell will execute. A
     * parser would also have to be kept agreeing with GitHub's, which is a second
     * definition of a thing only one party actually decides.
     *
     * @return list<PipelineStep>
     */
    public function workflowSteps(): array
    {
        $steps = [];

        foreach ($this->workflowFiles() as $path) {
            $workflow = basename($path);
            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
            $open = null;

            foreach ($lines as $offset => $line) {
                // A step opens on `- name:`, and also on a bare `- run:` or `- uses:`.
                //
                // The bare forms are not a tidiness detail. ci.yml's `js` job is four
                // unnamed steps -- `- run: pnpm lint`, `- run: pnpm format:check`,
                // `- run: pnpm typecheck`, `- run: pnpm test` -- so a parser keyed on
                // `- name:` sees none of them, and the four JS gates drop out of the
                // inventory without anything going red. That is this whole exercise
                // committed by the tool written to prevent it, so the parser reads the
                // shape GitHub accepts rather than the shape most steps happen to use.
                if (preg_match('/^(\s*)-\s+(name|run|uses):\s*(.*?)\s*$/', $line, $match) === 1) {
                    if ($open !== null) {
                        $steps[] = self::close($open);
                    }

                    $open = [
                        'workflow' => $workflow,
                        'name' => $match[2] === 'name'
                            ? trim($match[3], "'\" ")
                            : trim($match[2] . ': ' . $match[3]),
                        'line' => $offset + 1,
                        'indent' => strlen($match[1]),
                        'body' => $match[2] === 'name' ? [] : [$match[2] . ': ' . $match[3]],
                        'coe' => false,
                    ];

                    continue;
                }

                if ($open === null) {
                    continue;
                }

                // A sibling list item at or below this step's indentation ends it.
                if (preg_match('/^(\s*)-\s+\S/', $line, $match) === 1 && strlen($match[1]) <= $open['indent']) {
                    $steps[] = self::close($open);
                    $open = null;

                    continue;
                }

                if (preg_match('/^\s+continue-on-error:\s*true\s*$/', $line) === 1) {
                    $open['coe'] = true;
                }

                $open['body'][] = $line;
            }

            if ($open !== null) {
                $steps[] = self::close($open);
            }
        }

        return $steps;
    }

    /**
     * First-party scripts run by a step that can fail the build, mapped to the workflows.
     *
     * @return array<string, list<string>>
     */
    public function scriptsInvokedByBlockingSteps(): array
    {
        $found = [];

        foreach ($this->workflowSteps() as $step) {
            if (!$step->blocks()) {
                continue;
            }

            foreach ($this->scriptsInvokedIn($step->run) as $path) {
                $found[$path][] = $step->workflow;
            }
        }

        return array_map(
            static fn(array $workflows): array => array_values(array_unique($workflows)),
            $found,
        );
    }

    /**
     * Checkers sitting beside an invoked script that nothing in the pipeline runs.
     *
     * @return list<string>
     */
    public function orphanedCheckers(): array
    {
        $invoked = $this->filesInvokedByPipeline();
        $orphans = [];

        foreach (array_unique(array_map(dirname(...), $invoked)) as $directory) {
            foreach (glob($this->root . '/' . $directory . '/*') ?: [] as $candidate) {
                $relative = $directory . '/' . basename($candidate);

                if (!is_file($candidate) || in_array($relative, $invoked, true)) {
                    continue;
                }

                if (self::canRefuse((string) file_get_contents($candidate))) {
                    $orphans[] = $relative;
                }
            }
        }

        $orphans = array_values(array_unique($orphans));
        sort($orphans);

        return $orphans;
    }

    /**
     * Every first-party file the pipeline runs, whether from a workflow or from composer.
     *
     * Files rather than gate identities, because this is what decides which directories
     * get swept and which candidates are already accounted for. `bin/pulsar` contributes
     * three gates and one file; sweeping on the identities would find no file named
     * `bin/pulsar list` and re-add the entrypoint as an orphan of itself.
     *
     * @return list<string>
     */
    public function filesInvokedByPipeline(): array
    {
        $files = [];

        foreach ($this->workflowSteps() as $step) {
            if (!$step->blocks()) {
                continue;
            }

            $files = [...$files, ...$this->filesInvokedIn($step->run)];
        }

        foreach ($this->composerScripts() as $body) {
            $files = [...$files, ...$this->filesInvokedIn(is_string($body) ? $body : implode("\n", $body))];
        }

        return array_values(array_unique($files));
    }

    /**
     * Composer script names, mapped to the first-party gate identity each runs.
     *
     * Every script, not only the leaves of `qa`. `composer extension:coverage` is not a
     * leaf -- that is the finding recorded against it, that it gates nothing -- but it is
     * still the name a contributor and a negative test would use for
     * scripts/check_extension_coverage.php, and the alias has to resolve or the gate looks
     * uncovered while its negative test sits right there.
     *
     * @return array<string, string>
     */
    public function composerAliases(): array
    {
        $aliases = [];

        foreach ($this->composerScripts() as $name => $body) {
            if (!is_string($body)) {
                continue;
            }

            $identity = $this->scriptInvokedBy($body);

            if ($identity !== null) {
                $aliases['composer ' . $name] = $identity;
            }
        }

        return $aliases;
    }

    /**
     * The pnpm targets a blocking workflow step runs, mapped to where.
     *
     * A target counts only when package.json declares it as a script. That is the same
     * principle the composer half runs on -- the gate list comes out of the manifest the
     * repository already maintains -- and it is also what keeps `pnpm audit --json >
     * pnpm-audit-results.json` from contributing two imaginary gates. `audit` is pnpm's
     * own subcommand, and the thing that actually refuses on its output is
     * tools/ci/assert-no-advisories.php, which the script scan finds on its own.
     *
     * @return array<string, string>
     */
    public function pnpmGates(): array
    {
        $declared = $this->packageScripts();
        $found = [];

        foreach ($this->workflowSteps() as $step) {
            if (!$step->blocks()) {
                continue;
            }

            preg_match_all('/\bpnpm\s+(?:run\s+)?([a-z][a-z0-9:_-]*)/i', self::stripComments($step->run), $matches);

            foreach ($matches[1] as $target) {
                if (in_array($target, self::PNPM_BUILTINS, true) || !in_array($target, $declared, true)) {
                    continue;
                }

                $found['pnpm ' . $target] = 'run by ' . $step->workflow;
            }
        }

        return $found;
    }

    /**
     * Composer scripts a blocking workflow step runs that no first-party file backs.
     *
     * These are the gates whose refusal comes from a tool rather than from a script in
     * this repository: `composer bench:ci` is phpbench enforcing 77 #[Assert] budgets,
     * `composer mutation:diff` is Infection enforcing minCoveredMsi. The script scan
     * cannot see them -- there is no .php path in the step -- so without this source the
     * Tier A performance gate, the job actually named "Hard Gate", would not appear in an
     * inventory of gates.
     *
     * @return array<string, string>
     */
    public function composerGatesRunByWorkflows(): array
    {
        $declared = $this->composerScripts();
        $aliases = $this->composerAliases();
        $found = [];

        foreach ($this->workflowSteps() as $step) {
            if (!$step->blocks()) {
                continue;
            }

            preg_match_all(
                '/\bcomposer\s+([a-z0-9](?:[a-z0-9:_-]*[a-z0-9])?)/i',
                self::stripComments($step->run),
                $matches,
            );

            foreach ($matches[1] as $script) {
                $name = 'composer ' . $script;

                // Backed by a first-party file, or one of composer's own subcommands.
                if (isset($aliases[$name]) || !isset($declared[$script])) {
                    continue;
                }

                $found[$name] = 'run by ' . $step->workflow;
            }
        }

        return $found;
    }

    /**
     * The file behind a gate identity, which for the console is the entrypoint itself.
     */
    private static function fileOf(string $identity): string
    {
        return str_starts_with($identity, self::CONSOLE . ' ') ? self::CONSOLE : $identity;
    }

    /**
     * The custom analyzer rules, which are gates inside gates.
     *
     * `composer phpstan` passing tells you the configured rules found nothing. It does not
     * tell you the rules are still registered, still firing, or still matching the shapes a
     * real handler is written in -- PhpStanGateTest found the level and the `services:`
     * entry could both be removed with the suite green. Each rule is its own claim about
     * what cannot ship, so each is its own row.
     *
     * Derived by walking the rule directories, so a rule added next month is in scope
     * without anybody remembering to list it.
     *
     * @return array<string, string>
     */
    public function analyzerRules(): array
    {
        $found = [];

        $directories = [
            'phpstan rule' => 'tools/php/phpstan/Rules',
            'psalm hook' => 'tools/php/psalm-plugin/Hook',
        ];

        foreach ($directories as $kind => $directory) {
            foreach (glob($this->root . '/' . $directory . '/*.php') ?: [] as $path) {
                $found[$kind . ' ' . basename($path, '.php')] = 'registered in the analyzer '
                    . 'configuration `composer qa` runs, from ' . $directory;
            }
        }

        return $found;
    }

    /**
     * The suppression baselines something ratchets, read from the ceiling file.
     *
     * A baseline is a promise that a number goes down. Until a ratchet reads that number,
     * an entry added to silence a new finding changes a file and nothing else -- the same
     * shape as a gate that cannot fail, in a JSON document.
     *
     * The list comes out of tools/php/analysis-baseline-ceiling.json rather than out of a
     * glob, deliberately. That file is the existing declaration of which baselines are
     * ratcheted and which are known gaps, with a stated reason for each exclusion; globbing
     * for `*baseline*` would be a second definition of the same fact, drifting from the
     * first, which is the failure this whole enumeration is built to avoid.
     *
     * @return array<string, string>
     */
    public function baselineRatchets(): array
    {
        $raw = @file_get_contents($this->root . '/tools/php/analysis-baseline-ceiling.json');

        if (!is_string($raw)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $document */
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $ceilings = $document['ceilings'] ?? null;

        if (!is_array($ceilings)) {
            return [];
        }

        $found = [];

        foreach (array_keys($ceilings) as $baseline) {
            if (!is_string($baseline)) {
                continue;
            }

            $found[$baseline . ' ratchet'] = 'a suppression baseline with a recorded ceiling in '
                . 'tools/php/analysis-baseline-ceiling.json';
        }

        return $found;
    }

    /**
     * The script names package.json declares.
     *
     * @return list<string>
     */
    public function packageScripts(): array
    {
        $raw = @file_get_contents($this->root . '/package.json');

        if (!is_string($raw)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $package */
            $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $scripts = $package['scripts'] ?? null;

        if (!is_array($scripts)) {
            return [];
        }

        return array_values(array_filter(array_keys($scripts), is_string(...)));
    }

    /**
     * Gate names some test declares itself the negative test for, mapped to the files.
     *
     * @return array<string, list<string>>
     */
    public function declarations(): array
    {
        $declared = [];

        foreach ($this->testFiles() as $path) {
            foreach (GateCoverageIndex::guardedGates((string) file_get_contents($path)) as $gate) {
                $declared[$gate][] = $path;
            }
        }

        return $declared;
    }

    /**
     * Every test method in the suite, keyed by short class name.
     *
     * Backs the check that a `Class::method` declaration names something real. The ratchet
     * half's vocabulary is `ShortClassName::methodName`, and a misspelled one used to be
     * invisible to both enumerations at once: this test passed `::` names over as the
     * ratchet half's business, and the ratchet half validated its exemptions rather than
     * its declarations. Resolving them against the actual test methods -- rather than
     * against one enumeration's list of rules -- checks every such declaration wherever it
     * points, which is what the first version got wrong by only knowing about ratchets.
     *
     * @return array<string, list<string>>
     */
    public function testMethodsByClass(): array
    {
        $methods = [];

        foreach ($this->testFiles() as $path) {
            $class = basename($path, '.php');
            $declared = array_keys(GateCoverageIndex::testMethods((string) file_get_contents($path)));

            if ($declared === []) {
                continue;
            }

            /** @var list<string> $declared */
            $methods[$class] = [...($methods[$class] ?? []), ...$declared];
        }

        return $methods;
    }

    /**
     * Does this test source contain an assertion that watches something refuse?
     *
     * This is the part that notices a negative test quietly becoming a positive one. It
     * cannot prove the planted defect is still the right defect -- nothing short of
     * mutating the gate and re-running proves that, which is what the workstreams that
     * wrote these tests did by hand, once, and recorded. What it does prove is that the
     * file still contains an observation of failure, so deleting the failing case and
     * keeping the passing one -- the cheap decay, and the one that leaves a green suite --
     * stops satisfying the declaration the file makes.
     *
     * Prose is stripped first, for the same reason it is stripped everywhere else here: a
     * docblock explaining that a test "asserts the exit code is 1" must not be read as
     * asserting it.
     */
    public static function observesRefusal(string $source): bool
    {
        $stripped = self::stripPhpComments($source);

        $patterns = [
            // An exit code or finding count asserted to be something other than success.
            '/assertSame\s*\(\s*[1-9]\d*\s*,/',
            '/assertNotSame\s*\(\s*0\s*,/',
            '/assertNotSame\s*\(\s*\[\s*\]\s*,/',
            '/assertNotEquals\s*\(\s*0\s*,/',
            '/assertGreaterThan\s*\(\s*0\s*,/',
            '/assertNotEmpty\s*\(/',
            '/assertCount\s*\(\s*[1-9]/',
            // The ratchet idiom: the rule is asserted to have NAMED the planted defect in
            // its findings list. Weaker-looking than an exit code and in practice stronger,
            // because it pins WHICH finding was reported rather than only that one was.
            //
            // assertStringContainsString is deliberately NOT here, though it looks like the
            // same thing. PrTitleScopeGateTest uses it for both halves of its job -- for
            // 'PR title-scope mismatch' on the refusal and for 'title-scope rule passes' on
            // the happy path -- so accepting it would have let that file keep its
            // declaration after both of its refusals were mutated away. That is not
            // hypothetical: it is what the first version of this list did, and the
            // red-then-green run that was supposed to prove this check works came back
            // green and exposed it.
            '/assertContains\s*\(/',
            // A refusal surfaced as an exception rather than an exit code.
            '/expectException(?:Message(?:Matches)?)?\s*\(/',
            // PHPStan rule fixtures: the rule is asserted to report an error on a line.
            '/analyse\s*\(\s*\[[^\]]*\]\s*,\s*\[\s*\[/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $stripped) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A file that can hand back a non-zero status is a checker; one that cannot is a
     * worker, a generator or a library.
     *
     * The test is "does some exit carry an argument that is not literally zero", and it is
     * written that way after the narrower version -- a digit 1-9 immediately after the
     * parenthesis -- read scripts/wiring_check.php as incapable of refusing. That script
     * ends `exit($unwiredCount > 0 ? 1 : 0)`, so the narrow rule dropped a real gate out of
     * the inventory silently, in the direction of "nothing to cover here". Which is the
     * whole finding, committed by the tool written to detect it.
     */
    public static function canRefuse(string $source): bool
    {
        $stripped = self::stripPhpComments(self::stripComments($source));

        // Shell: `exit 1`, `exit $status`.
        if (preg_match('/^\s*exit\s+(?!0\s*$)\S/m', $stripped) === 1) {
            return true;
        }

        preg_match_all('/\bexit\s*\(([^()]*)\)/', $stripped, $matches);

        foreach ($matches[1] as $argument) {
            if (trim($argument) !== '' && trim($argument) !== '0') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function workflowFiles(): array
    {
        $files = array_map(
            static fn(string $path): string => str_replace('\\', '/', $path),
            glob($this->root . '/.github/workflows/*.yml') ?: [],
        );

        sort($files);

        return $files;
    }

    /**
     * First-party scripts a shell fragment actually runs.
     *
     * The invocation verb is required. `sha256sum tools/php/bench-pipeline.manifest.php`
     * names a file without running it, and reading that as an invocation would put a
     * required manifest into the gate inventory and demand a negative test for a file that
     * only ever gets hashed.
     *
     * @return list<string>
     */
    public function scriptsInvokedIn(string $shell): array
    {
        $found = [];

        foreach ($this->filesInvokedIn($shell) as $file) {
            // The console entrypoint is one file and many gates. `bin/pulsar list` is the
            // boot smoke test, `bin/pulsar optimize:validate` is the warmup pipeline and
            // `bin/pulsar i18n:slugs:lint` is the slug lint; they fail for unrelated
            // reasons and each needs its own planted defect. Collapsing them into one
            // "bin/pulsar" row would let a negative test for any one of them stand as
            // coverage for all three.
            if ($file === self::CONSOLE) {
                $found = [...$found, ...self::consoleCommandsIn($shell)];

                continue;
            }

            $found[] = $file;
        }

        return array_values(array_unique($found));
    }

    /**
     * The first-party files a shell fragment runs, without splitting the console up.
     *
     * @return list<string>
     */
    public function filesInvokedIn(string $shell): array
    {
        $text = self::stripComments($shell);
        $found = [];

        preg_match_all(
            '~(?:\bphp\b(?:\s+-d\s+\S+)*\s+|\bbash\s+|\bsh\s+|(?<![\w.-])\./)([A-Za-z0-9_./-]+\.(?:php|sh))~',
            $text,
            $matches,
        );

        foreach ($matches[1] as $candidate) {
            $relative = trim($candidate, './');

            if ($relative === '' || str_starts_with($relative, 'vendor/')) {
                continue;
            }

            if (is_file($this->root . '/' . $relative)) {
                $found[] = $relative;
            }
        }

        // scripts/qa and bin/pulsar carry no extension, so they are matched by name.
        foreach ([self::LOCAL_ENTRYPOINT, self::CONSOLE] as $extensionless) {
            $pattern = '~(?<![\w/.-])\.?/?' . preg_quote($extensionless, '~') . '(?![\w.-])~';

            if (preg_match($pattern, $text) === 1 && is_file($this->root . '/' . $extensionless)) {
                $found[] = $extensionless;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * `bin/pulsar <command>` invocations in a shell fragment.
     *
     * @return list<string>
     */
    private static function consoleCommandsIn(string $shell): array
    {
        preg_match_all(
            '~(?<![\w/.-])\.?/?' . preg_quote(self::CONSOLE, '~') . '\s+([a-z][a-z0-9:_-]*)~',
            self::stripComments($shell),
            $matches,
        );

        $commands = array_map(
            static fn(string $command): string => self::CONSOLE . ' ' . $command,
            $matches[1],
        );

        return array_values(array_unique($commands));
    }

    /**
     * The first first-party script a composer script body runs, if it runs one at all.
     */
    public function scriptInvokedBy(string $body): ?string
    {
        $scripts = $this->scriptsInvokedIn($body);

        return $scripts === [] ? null : $scripts[0];
    }

    /**
     * Every third-party action any workflow uses, without its version pin.
     *
     * These are the gates this inventory cannot reach. actionlint and zizmor judge the
     * workflows themselves and only run on a GitHub runner; the SLSA generator needs OIDC
     * id-token minting and a real release event. None can be driven from a PHPUnit
     * subprocess, so none can carry a negative test, and pretending otherwise would be the
     * finding restated.
     *
     * What is possible is to refuse to let the blind spot grow quietly. The census is
     * asserted against a recorded list, so a tenth action appearing -- a new scanner, a
     * new publisher, anything that can block a merge from outside this repository -- is a
     * deliberate edit somebody has to justify rather than a silent widening.
     *
     * @return list<string>
     */
    public function thirdPartyActions(): array
    {
        $actions = [];

        foreach ($this->workflowFiles() as $path) {
            preg_match_all(
                '/^\s*-?\s*uses:\s*([^\s@]+)/m',
                self::stripComments((string) file_get_contents($path)),
                $matches,
            );

            foreach ($matches[1] as $action) {
                $actions[] = trim($action, "'\" ");
            }
        }

        $actions = array_values(array_unique($actions));
        sort($actions);

        return $actions;
    }

    /**
     * Steps that are reports rather than gates, because a failure there is tolerated.
     *
     * @return list<PipelineStep>
     */
    public function toleratedSteps(): array
    {
        return array_values(array_filter(
            $this->workflowSteps(),
            static fn(PipelineStep $step): bool => !$step->blocks(),
        ));
    }

    /**
     * Every test file, wherever it sits.
     *
     * Recursive over the whole of tests/ rather than over a list of directories, because
     * the first version listed four and BoundaryCheckTest lives in a fifth. A declaration
     * the scanner cannot see is a declaration that does not count, so the gate it covers
     * reads as unguarded and the honest fix looks like adding an exemption -- the scan
     * quietly deciding what is true, in the direction of more exemptions.
     *
     * @return list<string>
     */
    private function testFiles(): array
    {
        $directory = $this->root . '/tests';

        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $entry->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param array{workflow: string, name: string, line: int, indent: int, body: list<string>, coe: bool} $open
     */
    private static function close(array $open): PipelineStep
    {
        return new PipelineStep(
            workflow: $open['workflow'],
            name: $open['name'],
            line: $open['line'],
            run: implode("\n", $open['body']),
            continueOnError: $open['coe'],
        );
    }

    private static function stripComments(string $text): string
    {
        $lines = preg_split('/\R/', $text);

        if ($lines === false) {
            return $text;
        }

        return implode("\n", array_filter(
            $lines,
            static fn(string $line): bool => preg_match('/^\s*#/', $line) !== 1,
        ));
    }

    /**
     * Removes PHP docblocks and line comments, so prose naming an assertion does not
     * register as making one.
     */
    private static function stripPhpComments(string $source): string
    {
        return (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);
    }

    /**
     * @param list<Gate> $gates
     *
     * @return list<Gate>
     */
    private function sorted(array $gates): array
    {
        $keyed = [];

        foreach ($gates as $gate) {
            $keyed[$gate->id] = $gate;
        }

        $names = array_keys($keyed);
        sort($names);

        return array_values(array_map(static fn(string $name): Gate => $keyed[$name], $names));
    }
}
