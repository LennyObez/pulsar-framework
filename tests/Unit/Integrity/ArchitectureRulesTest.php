<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\ImportAnalyzer;
use Pulsar\Tests\Unit\Integrity\Support\ModuleMap;
use Pulsar\Tests\Unit\Integrity\Support\VisibilityClassifier;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function preg_match;
use function sprintf;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const GLOB_ONLYDIR;
use const JSON_THROW_ON_ERROR;

/**
 * Architecture boundary enforcement tests.
 *
 * Scans all PHP files in src/ and extensions/\*\/src/ to enforce:
 * 1. No cross-module controller references
 * 2. No cross-module view references
 * 3. Cross-module imports must target #[Api] classes
 * 4. Adapter/Provider classes must not leak into Contract namespaces
 * 5. Core must have no forbidden vendor SDK dependencies
 *
 * Plus fixture validation tests that prove each rule's detection works.
 *
 * Run in isolation:
 *   vendor/bin/phpunit -c tools/php/phpunit.xml --filter ArchitectureRulesTest
 */
#[CoversNothing]
final class ArchitectureRulesTest extends TestCase
{
    /** Packages explicitly allowed in composer.json "require" (production deps). */
    private const array VENDOR_ALLOWLIST = [
        'php',
    ];

    /** Package prefix patterns allowed in composer.json "require". */
    private const array VENDOR_PREFIX_ALLOWLIST = [
        'ext-',
        'psr/',
    ];

    /** Package patterns explicitly forbidden (vendor SDK lock-in). */
    private const array VENDOR_DENYLIST = [
        'aws/',
        'google/',
        'azure/',
        'stripe/',
        'braintree/',
        'sentry/',
        'datadog/',
        'newrelic/',
        'bugsnag/',
        'twilio/',
        'sendgrid/',
    ];

    /** @var array<string, list<string>> filepath => list of referenced Pulsar FQCNs */
    private static array $fileReferences = [];

    /** @var array<string, string> filepath => namespace of the file */
    private static array $fileNamespaces = [];

    private static string $rootDir;

    public static function setUpBeforeClass(): void
    {
        self::$rootDir = dirname(__DIR__, 3);
        self::$fileReferences = [];
        self::$fileNamespaces = [];

        // Scan src/
        self::scanDirectory(self::$rootDir . DIRECTORY_SEPARATOR . 'src');

        // Scan extensions/*/src/
        $extDirs = glob(self::$rootDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'src', GLOB_ONLYDIR);
        if ($extDirs !== false) {
            foreach ($extDirs as $extDir) {
                self::scanDirectory($extDir);
            }
        }
    }

    // ---------------------------------------------------------------
    // Rule 1: No cross-module controller references
    // ---------------------------------------------------------------

    #[Test]
    public function cross_module_controller_references_are_forbidden(): void
    {
        $violations = [];

        foreach (self::$fileReferences as $filePath => $references) {
            $sourceNamespace = self::$fileNamespaces[$filePath] ?? '';

            // Composition roots are exempt — they wire cross-module services by design
            if (ModuleMap::isCompositionRoot($sourceNamespace)) {
                continue;
            }

            foreach ($references as $ref) {
                if (!ModuleMap::isController($ref)) {
                    continue;
                }

                if (ModuleMap::sameModule($sourceNamespace, $ref)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s references controller %s (different module)',
                    self::shortPath($filePath),
                    $ref,
                );
            }
        }

        self::assertEmpty(
            $violations,
            "Cross-module controller references found:\n- " . implode("\n- ", $violations),
        );
    }

    // ---------------------------------------------------------------
    // Rule 1b: No competitor-framework dependencies in production code
    // ---------------------------------------------------------------

    #[Test]
    public function competitor_framework_imports_are_forbidden_in_production_code(): void
    {
        // Pulsar builds its own components — production code must not depend on
        // competitor frameworks. This also guards against an undeclared
        // transitive dependency leaking into production: Symfony's Filesystem
        // was used here without a composer `require` entry and would fatal on
        // `composer install --no-dev`. Reimplement natively or use a PSR
        // interface (Psr\* is allowed — those are standards, not frameworks).
        $forbiddenPrefixes = [
            'Symfony\\',
            'Illuminate\\',
            'Laravel\\',
            'Doctrine\\',
            'GuzzleHttp\\',
            'Monolog\\',
            'Laminas\\',
            'Zend\\',
            'Nette\\',
            'Yiisoft\\',
            'Cake\\',
            'Slim\\',
        ];

        $violations = [];

        foreach (self::$fileReferences as $filePath => $references) {
            foreach ($references as $ref) {
                foreach ($forbiddenPrefixes as $prefix) {
                    if (str_starts_with($ref, $prefix)) {
                        $violations[] = sprintf('%s imports %s', self::shortPath($filePath), $ref);

                        break;
                    }
                }
            }
        }

        self::assertEmpty(
            $violations,
            'Production code (src/ + extensions/*/src/) must not import competitor frameworks — '
            . "Pulsar builds its own components. Reimplement natively or depend on a PSR interface.\nFound:\n- "
            . implode("\n- ", $violations),
        );
    }

    // ---------------------------------------------------------------
    // Rule 2: No cross-module view references
    // ---------------------------------------------------------------

    #[Test]
    public function cross_module_view_references_are_forbidden(): void
    {
        $violations = [];

        foreach (self::$fileReferences as $filePath => $references) {
            $sourceNamespace = self::$fileNamespaces[$filePath] ?? '';

            // Composition roots are exempt — they wire cross-module services by design
            if (ModuleMap::isCompositionRoot($sourceNamespace)) {
                continue;
            }

            foreach ($references as $ref) {
                if (!ModuleMap::isView($ref)) {
                    continue;
                }

                if (ModuleMap::sameModule($sourceNamespace, $ref)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s references view %s (different module)',
                    self::shortPath($filePath),
                    $ref,
                );
            }
        }

        self::assertEmpty(
            $violations,
            "Cross-module view references found:\n- " . implode("\n- ", $violations),
        );
    }

    // ---------------------------------------------------------------
    // Rule 3: Cross-module imports must target #[Api] classes
    //
    // Uses a baseline file to track known violations. The test fails
    // only when NEW violations appear (not in the baseline). Fixing
    // existing violations and removing them from the baseline is
    // encouraged. Regenerate baseline:
    //   vendor/bin/phpunit -c tools/php/phpunit.xml --filter cross_module_imports
    // then copy the "new violations" list into the baseline file.
    // ---------------------------------------------------------------

    /** Baseline: non-#[Api] classes that are known to be imported cross-module. */
    private const string BASELINE_PATH = __DIR__ . '/architecture-baseline.json';

    #[Test]
    public function cross_module_imports_must_target_public_api(): void
    {
        $baseline = self::loadBaseline();
        $violations = [];

        foreach (self::$fileReferences as $filePath => $references) {
            $sourceNamespace = self::$fileNamespaces[$filePath] ?? '';

            // Composition roots are exempt
            if (ModuleMap::isCompositionRoot($sourceNamespace)) {
                continue;
            }

            foreach ($references as $ref) {
                // Same module — no restriction
                if (ModuleMap::sameModule($sourceNamespace, $ref)) {
                    continue;
                }

                // Architecture metadata is accessible from everywhere by nature: it is
                // what the rules are written in, not something the rules govern. The two
                // attributes have always been here; CompositionRoots joins them because
                // it is the same kind of thing — the single definition of which classes
                // may cross module boundaries. It lived in triplicate precisely because
                // sharing it looked like a boundary violation, so each consumer wrote its
                // own, and the three drifted.
                if (
                    $ref === 'Pulsar\Api\Api'
                    || $ref === 'Pulsar\Api\Internal'
                    || $ref === 'Pulsar\Api\CompositionRoots'
                ) {
                    continue;
                }

                // Skip baselined classes (known debt)
                if (in_array($ref, $baseline, true)) {
                    continue;
                }

                if (!VisibilityClassifier::isCrossModuleSafe($ref)) {
                    $violations[] = sprintf(
                        '%s imports non-#[Api] class %s (cross-module)',
                        self::shortPath($filePath),
                        $ref,
                    );
                }
            }
        }

        self::assertEmpty(
            $violations,
            "NEW cross-module imports targeting non-#[Api] classes (not in baseline):\n- "
            . implode("\n- ", $violations)
            . "\n\nTo fix: add #[Api] to the target class, or move the import within the same module."
            . "\nTo baseline (last resort): add the target class to " . self::BASELINE_PATH,
        );
    }

    // ---------------------------------------------------------------
    // Rule 4: Adapters must not leak into Contracts
    // ---------------------------------------------------------------

    #[Test]
    public function adapters_must_not_leak_into_contracts(): void
    {
        $violations = [];

        foreach (self::$fileReferences as $filePath => $references) {
            $sourceNamespace = self::$fileNamespaces[$filePath] ?? '';

            if (!ModuleMap::isContract($sourceNamespace)) {
                continue;
            }

            foreach ($references as $ref) {
                if (ModuleMap::isAdapter($ref)) {
                    $violations[] = sprintf(
                        '%s (contract) imports adapter/provider %s',
                        self::shortPath($filePath),
                        $ref,
                    );
                }
            }
        }

        self::assertEmpty(
            $violations,
            "Adapter/provider classes leaked into contracts:\n- " . implode("\n- ", $violations),
        );
    }

    // ---------------------------------------------------------------
    // Rule 5: Core has no forbidden vendor SDK dependencies
    // ---------------------------------------------------------------

    #[Test]
    public function core_has_no_forbidden_vendor_dependencies(): void
    {
        $composerPath = self::$rootDir . DIRECTORY_SEPARATOR . 'composer.json';
        /** @var array{require?: array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents($composerPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        /** @var list<string> $require */
        $require = array_keys($composer['require'] ?? []);
        $forbidden = [];

        foreach ($require as $package) {
            // Check allowlist
            if (in_array($package, self::VENDOR_ALLOWLIST, true)) {
                continue;
            }

            // Check prefix allowlist
            $allowed = false;
            foreach (self::VENDOR_PREFIX_ALLOWLIST as $prefix) {
                if (str_starts_with($package, $prefix)) {
                    $allowed = true;

                    break;
                }
            }
            if ($allowed) {
                continue;
            }

            // Check denylist
            foreach (self::VENDOR_DENYLIST as $pattern) {
                if (str_starts_with($package, $pattern)) {
                    $forbidden[] = sprintf('%s (matches vendor SDK denylist pattern: %s)', $package, $pattern);

                    continue 2;
                }
            }
        }

        self::assertEmpty(
            $forbidden,
            "Core composer.json contains forbidden vendor SDK dependencies:\n- " . implode("\n- ", $forbidden),
        );
    }

    // ---------------------------------------------------------------
    // Rule 6: Deptrac config covers all extensions
    // ---------------------------------------------------------------

    #[Test]
    public function deptrac_config_covers_all_extensions(): void
    {
        $deptracPath = self::$rootDir . DIRECTORY_SEPARATOR . 'tools'
            . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'deptrac.yaml';

        $deptracContent = (string) file_get_contents($deptracPath);

        $extDirs = glob(
            self::$rootDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'src',
            GLOB_ONLYDIR,
        );

        $uncovered = [];

        if ($extDirs !== false) {
            foreach ($extDirs as $extDir) {
                // Extract extension name from path: extensions/{Name}/src
                if (preg_match('/extensions[\\\\\/]([^\\\\\/]+)[\\\\\/]src$/', $extDir, $matches) === 1) {
                    $extName = $matches[1];

                    // Convert directory name to PascalCase namespace segment:
                    // social-sso -> SocialSso, oauth2 -> OAuth2, ai-governance -> AiGovernance
                    $namespacePart = str_replace(' ', '', ucwords(str_replace('-', ' ', $extName)));

                    // Special case: oauth2 -> OAuth2 (standard casing)
                    $namespacePart = match ($namespacePart) {
                        'Oauth2' => 'OAuth2',
                        'Opentelemetry' => 'OpenTelemetry',
                        'Webauthn' => 'WebAuthn',
                        'ObservabilityExport' => 'ObservabilityExport',
                        'SocialSso' => 'SocialSso',
                        'AiGovernance' => 'AiGovernance',
                        'McpServer' => 'McpServer',
                        default => $namespacePart,
                    };

                    $namespace = 'Pulsar\\\\Extension\\\\' . $namespacePart;

                    if (!str_contains($deptracContent, $namespace)) {
                        $uncovered[] = $extName;
                    }
                }
            }
        }

        self::assertEmpty(
            $uncovered,
            "Extensions not covered by Deptrac config (tools/php/deptrac.yaml):\n- "
            . implode("\n- ", $uncovered)
            . "\n\nAdd layers for each extension. See docs/ARCHITECTURE_RULES.md.",
        );
    }

    // ---------------------------------------------------------------
    // Fixture validation tests — prove detection works
    // ---------------------------------------------------------------

    #[Test]
    public function fixture_detects_internal_cross_module_import(): void
    {
        $fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'Fixture'
            . DIRECTORY_SEPARATOR . 'FakeInternalConsumer.php';
        $refs = ImportAnalyzer::extractReferences($fixturePath);

        self::assertContains('Pulsar\Cache\FrameworkCache', $refs);
        self::assertContains('Pulsar\Observability\Log\Sink\DeferredSink', $refs);

        // Both are #[Internal] — verify classifier catches them
        self::assertFalse(VisibilityClassifier::isCrossModuleSafe('Pulsar\Cache\FrameworkCache'));
        self::assertFalse(VisibilityClassifier::isCrossModuleSafe('Pulsar\Observability\Log\Sink\DeferredSink'));
    }

    #[Test]
    public function fixture_detects_cross_module_controller_ref(): void
    {
        $fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'Fixture'
            . DIRECTORY_SEPARATOR . 'FakeControllerCrossRef.php';
        $refs = ImportAnalyzer::extractReferences($fixturePath);

        self::assertContains('Pulsar\Extension\Studio\Server\Controller\ApiController', $refs);
        self::assertTrue(ModuleMap::isController('Pulsar\Extension\Studio\Server\Controller\ApiController'));

        // Fixture is in Tests module, ApiController is in Studio — different modules
        self::assertFalse(ModuleMap::sameModule(
            'Pulsar\Tests\Unit\Integrity\Fixture\FakeControllerCrossRef',
            'Pulsar\Extension\Studio\Server\Controller\ApiController',
        ));
    }

    #[Test]
    public function fixture_detects_adapter_leak_in_contract(): void
    {
        $fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'Fixture'
            . DIRECTORY_SEPARATOR . 'FakeAdapterLeak.php';
        $refs = ImportAnalyzer::extractReferences($fixturePath);

        self::assertContains(
            'Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider',
            $refs,
        );
        self::assertTrue(ModuleMap::isAdapter(
            'Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider',
        ));
        self::assertTrue(ModuleMap::isContract(
            'Pulsar\Extension\Payments\Contracts\FakeAdapterLeak',
        ));
    }

    #[Test]
    public function fixture_detects_cross_module_view_ref(): void
    {
        $fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'Fixture'
            . DIRECTORY_SEPARATOR . 'FakeViewCrossRef.php';
        $refs = ImportAnalyzer::extractReferences($fixturePath);

        self::assertContains('Pulsar\Extension\Studio\Server\View\ViewRenderer', $refs);
        self::assertTrue(ModuleMap::isView('Pulsar\Extension\Studio\Server\View\ViewRenderer'));
    }

    #[Test]
    public function fixture_detects_fully_qualified_reference(): void
    {
        $fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'Fixture'
            . DIRECTORY_SEPARATOR . 'FakeFullyQualifiedRef.php';
        $refs = ImportAnalyzer::extractReferences($fixturePath);

        self::assertContains(
            'Pulsar\Cache\FrameworkCache',
            $refs,
            'ImportAnalyzer must detect fully-qualified inline references (T_NAME_FULLY_QUALIFIED)',
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function scanDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getPathname();

            // Skip test fixtures
            if (str_contains($filePath, 'Fixture')) {
                continue;
            }

            // F23.4: combine static `use` / FQCN references with the
            // class-string scan so `$container->get('Pulsar\Foo\Bar')`
            // and `class_exists('Pulsar\Foo\Bar')` are not invisible
            // to boundary-enforcement. Without the merge the
            // arbitrary-class-instantiation pattern bypasses the
            // architecture rules — exactly the case those rules
            // exist to surface.
            $staticRefs = ImportAnalyzer::extractReferences($filePath);
            $stringRefs = ImportAnalyzer::extractClassStringReferences($filePath);
            $references = array_values(array_unique([...$staticRefs, ...$stringRefs]));
            if ($references !== []) {
                self::$fileReferences[$filePath] = $references;
            }

            // Extract namespace for module identification
            $namespace = self::extractNamespace($filePath);
            if ($namespace !== null) {
                self::$fileNamespaces[$filePath] = $namespace;
            }
        }
    }

    private static function extractNamespace(string $filePath): ?string
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            return null;
        }

        $tokens = token_get_all($code);
        $namespace = '';

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] !== T_NAMESPACE) {
                continue;
            }

            // Skip whitespace after "namespace"
            $i++;
            while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $i++;
            }

            // Collect the namespace name
            while ($i < $count && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                if (is_array($tokens[$i])) {
                    $namespace .= $tokens[$i][1];
                }
                $i++;
            }

            break;
        }

        // Append class name from filename for FQCN
        $basename = basename($filePath, '.php');

        return $namespace !== '' ? $namespace . '\\' . $basename : null;
    }

    /**
     * Load the baseline of known cross-module non-#[Api] import targets.
     *
     * @return list<string> Baselined FQCNs
     */
    private static function loadBaseline(): array
    {
        if (!file_exists(self::BASELINE_PATH)) {
            return [];
        }

        $content = file_get_contents(self::BASELINE_PATH);
        if ($content === false) {
            return [];
        }

        /** @var array<string, string> $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> */
        return array_keys($data);
    }

    private static function shortPath(string $filePath): string
    {
        return str_replace(self::$rootDir . DIRECTORY_SEPARATOR, '', $filePath);
    }
}
