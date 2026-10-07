<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\NistCsfMapping;
use Pulsar\Compliance\Probe\AiMonitoringProbe;
use Pulsar\Compliance\Probe\CapabilityProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Tests\Unit\Documentation\Support\TrackedFiles;
use ReflectionClass;
use ReflectionMethod;

use function dirname;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;

use const DIRECTORY_SEPARATOR;

/**
 * The README's first screen is the only page most readers will read.
 *
 * It opened with two shields and a status line, said nothing a build could
 * contradict, and pointed its CI badge at a workflow without naming a branch. It
 * now makes four checkable claims about this tree instead, and a claim about a
 * tree is worth exactly as much as the check that reads the tree back.
 *
 * These do not grade the prose. They fail when the repository stops matching what
 * the front page says about it — which is the failure that matters, because the
 * front page is where a stranger decides whether to trust the rest.
 */
#[CoversNothing]
final class ReadmeClaimsTest extends TestCase
{
    /**
     * Every relative link on the front page must resolve.
     *
     * The first screen now links source files and ADRs by path as evidence. A
     * dead one turns evidence into decoration.
     */
    #[Test]
    public function everyRelativeLinkInTheReadmeResolves(): void
    {
        $root = dirname(__DIR__, 3);
        preg_match_all('/\]\(([^)#\s]+)(?:#[^)\s]*)?\)/', $this->readme(), $matches);

        $missing = [];

        foreach ($matches[1] as $target) {
            if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
                continue;
            }

            if (!file_exists($root . DIRECTORY_SEPARATOR . $target)) {
                $missing[] = $target;
            }
        }

        self::assertSame([], $missing, 'README links to paths that do not exist: ' . implode(', ', $missing));
    }

    /**
     * The CI badge must name the branch it reports.
     *
     * Unqualified, the badge renders the workflow's latest run on the default
     * branch while reading, to anyone glancing at it, as the state of the code
     * they are looking at. Development happens on branches here, so the two were
     * months apart. Pinning it does not make it fresher; it makes it honest about
     * what it is measuring, and the README says the rest out loud.
     */
    #[Test]
    public function theCiBadgeNamesItsBranch(): void
    {
        if (preg_match('#actions/workflows/ci\.yml/badge\.svg\?branch=([a-zA-Z0-9._/-]+)#', $this->readme(), $matches) !== 1) {
            self::fail('The CI badge does not pin a branch, so it reports a run the reader cannot identify');
        }

        self::assertSame('main', $matches[1]);
    }

    /**
     * The claim: a compliance control cannot report itself satisfied.
     *
     * Every public entry point into a declaration is checked, not just the one
     * the README shows, because a status parameter added to any of them would
     * make the front page false.
     */
    #[Test]
    public function noControlDeclarationEntryPointAcceptsAStatus(): void
    {
        self::assertStringContainsString(
            'no status parameter',
            $this->readme(),
            'This test exists to back a README claim that is no longer made',
        );

        $reflection = new ReflectionClass(ControlDeclaration::class);
        $offenders = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if (str_contains(strtolower($parameter->getName()), 'status')) {
                    $offenders[] = $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A control status can now be written as a literal: ' . implode(', ', $offenders),
        );
    }

    /**
     * The README names the monitoring hook that ships, and the probe measures it run.
     *
     * Replaces theMonitoringHookGapTheReadmeNamesIsStillOpen, which guarded the claim
     * that no implementation existed and failed the day GovernanceConformityHook landed.
     * Registration is proven by running the wiring, in
     * RiskTierDeploymentWiringTest::theArticle72LimbIsMetByTheHookTheShippedWiringRegisters.
     */
    #[Test]
    public function theMonitoringHookTheReadmeNamesIsShipped(): void
    {
        $readme = $this->readme();
        self::assertStringContainsString('GovernanceConformityHook', $readme, 'This test backs a README claim that is no longer made');
        self::assertDoesNotMatchRegularExpression('/MonitoringHookInterface[^.]*\b(?:has no implementation|no implementation anywhere|none exists|zero implementations)/i', $readme);
        self::assertContains(
            'extensions/ai-governance/src/Internal/Monitoring/GovernanceConformityHook.php',
            $this->implementorsOf('MonitoringHookInterface', 'extensions/ai-governance/src'),
        );

        // The stores behind the hook are durable by default, in the class and in the shipped
        // config; AiGovernanceConfigTest already covers fromArray([]).
        self::assertDoesNotMatchRegularExpression('/in-memory reference (?:stores|implementations)/i', $readme);
        $defaults = new AiGovernanceConfig();
        $shipped = require $this->root() . '/extensions/ai-governance/config/ai-governance.php';
        self::assertIsArray($shipped);

        foreach ([
            'registryStore' => 'registry_store',
            'impactAssessmentStore' => 'impact_assessment_store',
            'dataGovernanceStore' => 'data_governance_store',
            'explainabilityStore' => 'explainability_store',
            'monitoringRecordStore' => 'monitoring_record_store',
            'transparencyStore' => 'transparency_store',
            'oversightStore' => 'oversight_store',
        ] as $property => $key) {
            self::assertSame(AiGovernanceConfig::DATABASE, $defaults->{$property}, $property);
            self::assertSame(AiGovernanceConfig::DATABASE, $shipped[$key] ?? null, $key);
        }

        $ids = $this->requiredIds(new AiMonitoringProbe());
        self::assertContains(ObservationId::AiMonitoringExercised, $ids);
        self::assertNotContains(ObservationId::AiMonitoringHookResolved, $ids, 'a resolved binding is not a measurement');
    }

    /** RC.RP is decided by the round trip, not by the backup class resolving. */
    #[Test]
    public function theBackupPrimitiveTheReadmeNamesShipsAndRcRpIsMeasured(): void
    {
        $readme = $this->readme();
        self::assertStringContainsString('SealedArchiveBackupService', $readme);
        self::assertDoesNotMatchRegularExpression('/no backup or restore primitive|backup primitive[^.|]*does not ship/i', $readme);
        self::assertTrue(new ReflectionClass(SealedArchiveBackupService::class)->implementsInterface(BackupServiceInterface::class));
        self::assertSame([ObservationId::BackupRoundTripVerified], $this->requiredIds(new RecoveryCapabilityProbe()));

        $rcRp = array_values(array_filter(NistCsfMapping::declarations(), static fn(ControlDeclaration $d): bool => $d->id === 'NIST-RC.RP'));
        self::assertCount(1, $rcRp);
        self::assertInstanceOf(RecoveryCapabilityProbe::class, $rcRp[0]->probe);
    }

    /** The gaps the README still names are still open; the day one closes, the paragraph is stale. */
    #[Test]
    public function theGapsTheReadmeStillNamesAreStillOpen(): void
    {
        $readme = $this->readme();
        foreach (['DsarStoreInterface', 'token_vault', 'Art. 15'] as $gap) {
            self::assertStringContainsString($gap, $readme);
        }

        self::assertSame([], $this->implementorsOf('DsarStoreInterface', 'src', 'extensions'), 'DsarStoreInterface now has an implementation; README.md gap paragraph is stale');

        $creates = [];
        foreach ($this->trackedPhpUnder(...$this->migrationDirectories()) as $file) {
            $source = (string) file_get_contents($this->root() . '/' . $file);
            if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)?\s+[`"]?token_vault\b|create(?:Table)?\s*\(\s*[\'"]token_vault[\'"]|TableDefinition\(\s*[\'"]token_vault[\'"]/i', $source) === 1) {
                $creates[] = $file;
            }
        }
        self::assertSame([], $creates, 'a migration now creates token_vault; README gap paragraph is stale');

        self::assertSame(
            [],
            array_values(array_filter(GdprMapping::declarations(), static fn(ControlDeclaration $d): bool => preg_match('/^Art\s*15/', $d->id) === 1)),
            'GdprMapping now declares Art. 15; README gap paragraph is stale',
        );
    }

    /**
     * Shipped PHP under the given directories, test files excluded.
     *
     * @return list<string>
     */
    private function trackedPhpUnder(string ...$dirs): array
    {
        return array_values(array_filter(
            TrackedFiles::under($this->root(), '.php', ...$dirs),
            static fn(string $path): bool => !str_contains($path, '/tests/'),
        ));
    }

    /**
     * The shipped files declaring a class or enum that implements the interface.
     *
     * Read as code, comments removed: "implements" in a docblock followed by the
     * interface's name further on is prose, not a declaration.
     *
     * @return list<string>
     */
    private function implementorsOf(string $interfaceShortName, string ...$dirs): array
    {
        $pattern = '/\b(?:class|enum)\s+\w+[^{;]*\bimplements\b[^{;]*\b' . preg_quote($interfaceShortName, '/') . '\b/';

        return array_values(array_filter(
            $this->trackedPhpUnder(...$dirs),
            fn(string $path): bool => preg_match($pattern, $this->codeOf($path)) === 1,
        ));
    }

    private function codeOf(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($this->root() . '/' . $path)) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * Every directory a migration ships from: the framework's modules, the root, and each
     * extension manifest's provides.migrations, at any depth.
     *
     * @return list<string>
     */
    private function migrationDirectories(): array
    {
        $root = $this->root();
        $dirs = array_map(static fn(string $d): string => substr($d, strlen($root) + 1), glob($root . '/src/*/Database/Migration', GLOB_ONLYDIR) ?: []);
        if (is_dir($root . '/database/migrations')) {
            $dirs[] = 'database/migrations';
        }

        foreach (TrackedFiles::under($root, '/pulsar.json', 'extensions') as $manifest) {
            $decoded = json_decode((string) file_get_contents($root . '/' . $manifest), true);
            $declared = is_array($decoded) && is_array($decoded['provides'] ?? null) && is_array($decoded['provides']['migrations'] ?? null) ? $decoded['provides']['migrations'] : [];
            foreach ($declared as $dir) {
                if (is_string($dir)) {
                    $dirs[] = dirname($manifest) . '/' . $dir;
                }
            }
        }

        self::assertNotSame([], $dirs, 'no migration directory was found');

        return $dirs;
    }

    /** @return list<ObservationId> */
    private function requiredIds(CapabilityProbe $probe): array
    {
        return array_map(static fn(RequiredFact $f): ObservationId => $f->id, $probe->requirement()->required);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function readme(): string
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'README.md';
        self::assertFileExists($path);

        $markdown = file_get_contents($path);
        self::assertIsString($markdown);

        return $markdown;
    }
}
