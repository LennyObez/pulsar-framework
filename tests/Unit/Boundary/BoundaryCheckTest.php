<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Boundary;

use BoundaryAnalyzer;

use function dirname;
use function file_put_contents;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function sys_get_temp_dir;
use function unlink;

require_once dirname(__DIR__, 3) . '/scripts/boundary_check.php';

#[CoversNothing]
final class BoundaryCheckTest extends TestCase
{
    private static string $snapshotPath;

    private static string $rootDir;

    public static function setUpBeforeClass(): void
    {
        self::$rootDir = dirname(__DIR__, 3);
        self::$snapshotPath = self::$rootDir . '/tools/api/public-api.snapshot.json';
    }

    // ---------------------------------------------------------------
    // extractModule()
    // ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function extractModuleProvider(): iterable
    {
        yield 'core module' => ['Pulsar\\Auth\\Guard\\SessionGuard', 'Auth'];
        yield 'core module internal' => ['Pulsar\\Auth\\Internal\\TokenVerifier', 'Auth'];
        yield 'core root' => ['Pulsar\\Core\\Kernel', 'Core'];
        yield 'infrastructure' => ['Pulsar\\Http\\Request', 'Http'];
        yield 'extension' => ['Pulsar\\Extension\\Payments\\Gateway\\Stripe', 'Extension\\Payments'];
        yield 'extension contracts' => ['Pulsar\\Extension\\Payments\\Contracts\\GatewayInterface', 'Extension\\Payments'];
        yield 'app namespace' => ['App\\Models\\User', 'App'];
        yield 'pulsar app' => ['Pulsar\\App\\Services\\Foo', 'App'];
        yield 'non-pulsar' => ['Symfony\\Component\\HttpFoundation\\Request', null];
        yield 'foundation' => ['Pulsar\\Support\\AtomicFileWriter', 'Support'];
        yield 'nested feature' => ['Pulsar\\Observability\\Tracing\\Span', 'Observability'];
    }

    #[Test]
    #[DataProvider('extractModuleProvider')]
    public function extractModule_returns_correct_module(string $fqcn, ?string $expected): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertSame($expected, $analyzer->extractModule($fqcn));
    }

    // ---------------------------------------------------------------
    // isCompositionRoot()
    // ---------------------------------------------------------------

    #[Test]
    public function kernel_is_composition_root(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertTrue($analyzer->isCompositionRoot('Pulsar\\Core\\Kernel'));
        self::assertTrue($analyzer->isCompositionRoot('Pulsar\\Console\\Application'));
    }

    #[Test]
    public function regular_class_is_not_composition_root(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertFalse($analyzer->isCompositionRoot('Pulsar\\Auth\\AuthManager'));
    }

    // ---------------------------------------------------------------
    // isInternalNamespace()
    // ---------------------------------------------------------------

    #[Test]
    public function internal_namespace_is_detected(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertTrue($analyzer->isInternalNamespace('Pulsar\\Auth\\Internal\\TokenVerifier'));
        self::assertTrue($analyzer->isInternalNamespace('Pulsar\\Extension\\Payments\\Internal\\Foo'));
    }

    #[Test]
    public function public_namespace_is_not_internal(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertFalse($analyzer->isInternalNamespace('Pulsar\\Auth\\AuthManager'));
        self::assertFalse($analyzer->isInternalNamespace('Pulsar\\Http\\Request'));
    }

    // ---------------------------------------------------------------
    // hasApiAttribute()
    // ---------------------------------------------------------------

    #[Test]
    public function api_class_is_detected_via_snapshot(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);

        // Pulsar\Http\Request should be in the API snapshot
        self::assertTrue($analyzer->hasApiAttribute('Pulsar\\Http\\Request'));
    }

    #[Test]
    public function non_api_class_returns_false(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);

        // Internal classes should return false
        self::assertFalse($analyzer->hasApiAttribute('Pulsar\\Cache\\FrameworkCache'));
    }

    #[Test]
    public function unresolvable_class_returns_null(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertNull($analyzer->hasApiAttribute('Pulsar\\NonExistent\\FakeClass'));
    }

    // ---------------------------------------------------------------
    // isExtension() / isAppCode() / isExtensionFile()
    // ---------------------------------------------------------------

    #[Test]
    public function extension_detection(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertTrue($analyzer->isExtension('Pulsar\\Extension\\Payments\\Gateway\\Stripe'));
        self::assertFalse($analyzer->isExtension('Pulsar\\Auth\\AuthManager'));
    }

    #[Test]
    public function app_code_detection(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertTrue($analyzer->isAppCode('App\\Models\\User'));
        self::assertTrue($analyzer->isAppCode('Pulsar\\App\\Services\\Foo'));
        self::assertFalse($analyzer->isAppCode('Pulsar\\Auth\\AuthManager'));
    }

    #[Test]
    public function extension_file_detection(): void
    {
        $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
        self::assertTrue($analyzer->isExtensionFile('extensions/payments/src/Gateway.php'));
        self::assertFalse($analyzer->isExtensionFile('src/Auth/AuthManager.php'));
    }

    // ---------------------------------------------------------------
    // scanFile() — cross-module Internal\ import
    // ---------------------------------------------------------------

    #[Test]
    public function scan_detects_cross_module_internal_import(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Security;

            use Pulsar\Auth\Internal\TokenVerifier;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile($tmpFile, dirname($tmpFile), [], [], false, false);

            self::assertNotEmpty($violations);
            self::assertSame('cross_module_internal', $violations[0]['rule']);
            self::assertSame('error', $violations[0]['severity']);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function scan_allows_same_module_internal_import(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Auth;

            use Pulsar\Auth\Internal\TokenVerifier;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile($tmpFile, dirname($tmpFile), [], [], false, false);

            self::assertEmpty($violations);
        } finally {
            unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    // scanFile() — extension importing App code
    // ---------------------------------------------------------------

    #[Test]
    public function scan_detects_extension_importing_app_code(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Extension\Example;

            use App\Models\User;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile($tmpFile, dirname($tmpFile), [], [], false, false);

            self::assertNotEmpty($violations);
            self::assertSame('extension_depends_on_app', $violations[0]['rule']);
            self::assertSame('error', $violations[0]['severity']);
        } finally {
            unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    // Severity — diff-based gating
    // ---------------------------------------------------------------

    #[Test]
    public function changed_file_gets_error_severity(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Auth;

            use Pulsar\Cache\FrameworkCache;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);
        $relativePath = basename($tmpFile);

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile(
                $tmpFile,
                dirname($tmpFile),
                [$relativePath], // This file is "changed"
                [],
                false,
                true, // hasDiffBase
            );

            self::assertNotEmpty($violations);
            self::assertSame('error', $violations[0]['severity']);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function unchanged_file_gets_warning_severity(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Auth;

            use Pulsar\Cache\FrameworkCache;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile(
                $tmpFile,
                dirname($tmpFile),
                [], // No changed files
                [],
                false,
                true, // hasDiffBase
            );

            self::assertNotEmpty($violations);
            self::assertSame('warning', $violations[0]['severity']);
        } finally {
            unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    // Severity — strict mode
    // ---------------------------------------------------------------

    #[Test]
    public function strict_mode_makes_all_violations_errors(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Auth;

            use Pulsar\Cache\FrameworkCache;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile(
                $tmpFile,
                dirname($tmpFile),
                [],
                [],
                true, // strict
                false,
            );

            self::assertNotEmpty($violations);
            self::assertSame('error', $violations[0]['severity']);
        } finally {
            unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    // Baseline suppression
    // ---------------------------------------------------------------

    #[Test]
    public function baseline_suppresses_known_violations(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Auth;

            use Pulsar\Cache\FrameworkCache;

            class Foo {}
            PHP;

        $tmpFile = $this->writeTempFile($code);
        $relativePath = basename($tmpFile);

        try {
            // Build baseline key: relative_path|import
            $baseline = [$relativePath . '|Pulsar\\Cache\\FrameworkCache' => true];

            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile(
                $tmpFile,
                dirname($tmpFile),
                [],
                $baseline,
                false,
                false,
            );

            self::assertEmpty($violations);
        } finally {
            unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    // Composition root exemption
    // ---------------------------------------------------------------

    #[Test]
    public function composition_root_is_exempt(): void
    {
        $code = <<<'PHP'
            <?php
            namespace Pulsar\Core;

            use Pulsar\Auth\AuthManager;
            use Pulsar\Cache\FrameworkCache;

            class Kernel {}
            PHP;

        $tmpFile = $this->writeTempFile($code, 'Kernel.php');

        try {
            $analyzer = new BoundaryAnalyzer(self::$snapshotPath);
            $violations = $analyzer->scanFile($tmpFile, dirname($tmpFile), [], [], false, false);

            self::assertEmpty($violations);
        } finally {
            unlink($tmpFile);
        }
    }

    // ---------------------------------------------------------------
    // JSON output schema (integration via CLI)
    // ---------------------------------------------------------------

    #[Test]
    public function json_output_is_valid(): void
    {
        $script = self::$rootDir . '/scripts/boundary_check.php';
        $output = [];
        exec(
            sprintf('php %s --json 2>&1', escapeshellarg($script)),
            $output,
            $exitCode,
        );

        $json = implode("\n", $output);
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertArrayHasKey('scanned', $data);
        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('warnings', $data);
        self::assertArrayHasKey('violations', $data);
        self::assertIsInt($data['scanned']);
        self::assertIsInt($data['errors']);
        self::assertIsInt($data['warnings']);
        self::assertIsArray($data['violations']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function writeTempFile(string $code, string $filename = 'TestBoundary.php'): string
    {
        $dir = sys_get_temp_dir();
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $code);

        return $path;
    }
}
