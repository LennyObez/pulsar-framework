<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Build\ArtifactEntry;
use Pulsar\Build\ArtifactIntegrityVerifier;
use Pulsar\Build\BuildException;
use Pulsar\Build\BuildManifest;
use Pulsar\Build\BuildMetadata;
use Pulsar\Build\PreloadGenerator;
use Pulsar\Build\VerificationStatus;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\I18nConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\Compiled\ContainerCompiler;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Core\KernelInterface;
use Pulsar\Core\Version;
use Pulsar\Event\Internal\EventMapCompiler;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extension\Compiler\ExtensionGraphCompiler;
use Pulsar\I18n\Compiler\I18nCatalogCompiler;
use Pulsar\Routing\Router;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Throwable;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function date;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function number_format;
use function rename;
use function serialize;
use function sprintf;
use function strlen;
use function unlink;

use const DATE_ATOM;
use const DIRECTORY_SEPARATOR;
use const PHP_VERSION;

/**
 * Unified build command: compiles all production artifacts deterministically.
 *
 * Usage: build [--verify] [--sign] [--strict] [--runtime=fpm|persistent]
 *
 * Replaces `pulsar optimize` as the canonical production build command.
 * Produces content-addressed artifacts: same input always generates byte-identical output.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class BuildCommand extends Command
{
    /** @var list<string> Artifact files written during this build (for cleanup on failure) */
    private array $tmpFiles = [];

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'build';
        $this->description = 'Compile all production artifacts deterministically';
        $this->addOption('verify', 'Check existing artifacts against manifest (non-mutating)', 'V');
        $this->addOption('sign', 'Sign the build manifest with the application signing key', 'S');
        $this->addOption('strict', 'Fail if any closure-based route is detected', 's');
        $this->addOption('runtime', 'Target runtime for preload generation (fpm|persistent)', 'r', 'fpm');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Verify mode: non-mutating check of existing artifacts
        if ($input->hasOption('verify')) {
            return $this->executeVerify($output);
        }

        return $this->executeBuild($input, $output);
    }

    /**
     * Verify existing artifacts against the build manifest.
     */
    private function executeVerify(OutputInterface $output): int
    {
        $cacheDir = $this->resolveCacheDir();

        if ($cacheDir === null) {
            $output->errorln('Cannot resolve cache directory. Ensure config is loaded.');

            return ExitCode::Error->value;
        }

        $manifestPath = $cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json';

        if (!is_file($manifestPath)) {
            $output->errorln('No build manifest found. Run `pulsar build` first.');

            return ExitCode::Error->value;
        }

        $json = file_get_contents($manifestPath);

        if ($json === false) {
            $output->errorln('Failed to read build manifest.');

            return ExitCode::Error->value;
        }

        $manifest = BuildManifest::fromJson($json);
        $verifier = new ArtifactIntegrityVerifier();
        $result = $verifier->verify($manifest, $cacheDir);

        if ($result->passed) {
            $output->writeln(sprintf('All %d artifacts verified successfully.', count($manifest->artifacts)));

            return ExitCode::Success->value;
        }

        $output->errorln('Artifact verification failed:');

        foreach ($result->entries as $key => $status) {
            if ($status !== VerificationStatus::Ok) {
                $output->errorln(sprintf('  %s: %s', $key, $status->value));
            }
        }

        foreach ($result->errors as $error) {
            $output->errorln('  ' . $error);
        }

        $output->errorln();
        $output->errorln('Run `pulsar build` to rebuild artifacts.');

        return ExitCode::Error->value;
    }

    /**
     * Execute the full build pipeline.
     */
    private function executeBuild(InputInterface $input, OutputInterface $output): int
    {
        $buildStart = hrtime(true);

        $cacheDir = $this->resolveCacheDir();

        if ($cacheDir === null) {
            $output->errorln('Cannot resolve cache directory. Ensure config is loaded.');

            return ExitCode::Error->value;
        }

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0o750, true);
        }

        $strict = $input->hasOption('strict');
        $sign = $input->hasOption('sign');
        $runtimeValue = $input->getStringOption('runtime', 'fpm');
        $runtime = $runtimeValue === 'persistent' ? RuntimeType::Persistent : RuntimeType::Fpm;

        $output->writeln('Building production artifacts...');
        $output->writeln();

        $artifacts = [];
        $contentHashes = [];

        try {
            // Step 1: Compile config
            $configResult = $this->compileConfig($cacheDir, $output);

            if ($configResult !== null) {
                $artifacts['config'] = $configResult;
            }

            // Step 2: Compile container
            $containerResult = $this->compileContainer($cacheDir, $output);

            if ($containerResult !== null) {
                $artifacts['container'] = $containerResult;
            }

            // Step 3: Compile routes
            $routesResult = $this->compileRoutes($cacheDir, $output, $strict);

            if ($routesResult !== null) {
                $artifacts['routes'] = $routesResult;
            }

            // Step 4: Compile extension graph manifest
            $extensionsResult = $this->compileExtensions($cacheDir, $output, $contentHashes);

            if ($extensionsResult !== null) {
                $artifacts['extensions'] = $extensionsResult;
            }

            // Step 5: Compile event listener map
            $eventsResult = $this->compileEventMap($cacheDir, $output, $contentHashes);

            if ($eventsResult !== null) {
                $artifacts['events'] = $eventsResult;
            }

            // Step 6: Compile i18n catalog index
            $i18nResult = $this->compileI18n($cacheDir, $output, $contentHashes);

            if ($i18nResult !== null) {
                $artifacts['i18n'] = $i18nResult;
            }

            // Step 7: Generate preload file
            $preloadResult = $this->generatePreload($cacheDir, $output, $runtime);

            if ($preloadResult !== null) {
                $artifacts['preload'] = $preloadResult;
            }

            // Step 8: Generate build manifest (written LAST for atomicity)
            $manifest = new BuildManifest(
                version: 1,
                algorithm: 'sha256',
                artifacts: $artifacts,
                contentHashes: $contentHashes,
            );

            // Optional signing
            if ($sign) {
                $manifest = $this->signManifest($manifest, $output);
            }

            $this->atomicWrite(
                $cacheDir . DIRECTORY_SEPARATOR . 'build-manifest.json',
                $manifest->toJson(),
            );

            // Step 9: Generate build metadata (informational only)
            $metadata = new BuildMetadata(
                builtAt: date(DATE_ATOM),
                phpVersion: PHP_VERSION,
                pulsarVersion: Version::full(),
            );

            $this->atomicWrite(
                $cacheDir . DIRECTORY_SEPARATOR . 'build-metadata.json',
                $metadata->toJson(),
            );
        } catch (Throwable $e) {
            // Clean up any tmp files on failure
            $this->cleanupTmpFiles();

            $output->errorln();
            $output->errorln('Build failed: ' . $e->getMessage());

            return ExitCode::Error->value;
        }

        $buildUs = (int) ((hrtime(true) - $buildStart) / 1000);
        $buildMs = $buildUs / 1000;

        $totalSize = 0;

        foreach ($artifacts as $artifact) {
            $totalSize += $artifact->size;
        }

        $output->writeln();
        $output->writeln(sprintf(
            'Build complete: %d artifact(s), %s total, %.1fms',
            count($artifacts),
            $this->formatBytes($totalSize),
            $buildMs,
        ));

        if ($sign && $manifest->signature !== null) {
            $output->writeln('  Manifest signed.');
        }

        return ExitCode::Success->value;
    }

    /**
     * Compile config into a cached PHP file.
     *
     * Serializes the ConfigRepository (typed DTO store) into a PHP file
     * that can be loaded at boot time. Uses PHP serialization for full
     * fidelity of typed DTOs, matching ConfigCache's approach.
     */
    private function compileConfig(string $cacheDir, OutputInterface $output): ?ArtifactEntry
    {
        $repository = $this->getConfigRepository();

        if ($repository === null) {
            $output->writeln('  Config: skipped (no config loaded)');

            return null;
        }

        // Serialize the typed DTO store: deterministic for identical config state
        $serialized = serialize($repository);
        $content = "<?php\n\ndeclare(strict_types=1);\n\n// Auto-generated by Pulsar BuildCommand. Do not edit.\nreturn unserialize(" . var_export($serialized, true) . ", ['allowed_classes' => false]);\n";

        $path = 'config.compiled.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln('  Config: compiled');

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Compile the DI container.
     */
    private function compileContainer(string $cacheDir, OutputInterface $output): ?ArtifactEntry
    {
        $container = $this->kernel->container();

        if (!method_exists($container, 'getDefinitions')) {
            $output->writeln('  Container: skipped (no definitions available)');

            return null;
        }

        /** @var array<string, ServiceDefinition> $definitions */
        $definitions = $container->getDefinitions();

        if ($definitions === []) {
            $output->writeln('  Container: skipped (no service definitions)');

            return null;
        }

        $content = ContainerCompiler::compile($definitions);

        $path = 'container.compiled.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln(sprintf('  Container: %d service(s) compiled', count($definitions)));

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Compile routes.
     */
    private function compileRoutes(string $cacheDir, OutputInterface $output, bool $strict): ?ArtifactEntry
    {
        $container = $this->kernel->container();

        if (!$container->has(Router::class)) {
            $output->writeln('  Routes: skipped (no router)');

            return null;
        }

        /** @var Router $router */
        $router = $container->get(Router::class);
        $routes = $router->routes;

        if ($routes === []) {
            $output->writeln('  Routes: skipped (no routes registered)');

            return null;
        }

        // Strict mode: fail on closure routes
        if ($strict) {
            $closureRoutes = [];

            foreach ($routes as $route) {
                if ($route->handler instanceof Closure) {
                    $closureRoutes[] = $route->path;
                }
            }

            if ($closureRoutes !== []) {
                throw BuildException::compilationFailed('routes', sprintf(
                    'Strict mode: %d closure-based route(s) found. Convert all handlers to class-based: %s',
                    count($closureRoutes),
                    implode(', ', $closureRoutes),
                ));
            }
        }

        // Serialize routes deterministically
        $routeData = [];

        foreach ($routes as $route) {
            if ($route->handler instanceof Closure) {
                continue;
            }

            $routeData[] = [
                'methods' => array_map(static fn($m) => $m->value, $route->methods),
                'path' => $route->path,
                'handler' => $route->handler,
                'name' => $route->name,
                'attributes' => $route->attributes,
                'middleware' => $route->middleware,
                'constraints' => $route->constraints,
                'host' => $route->host,
            ];
        }

        $content = "<?php\n\ndeclare(strict_types=1);\n\n// Auto-generated by Pulsar BuildCommand. Do not edit.\nreturn " . var_export($routeData, true) . ";\n";

        $path = 'routes.compiled.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln(sprintf('  Routes: %d route(s) compiled', count($routeData)));

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Compile extension graph manifest.
     *
     * Uses the already-loaded extension registry from ExtensionBootstrap
     * rather than re-discovering from disk, ensuring consistency with
     * the running kernel's extension state.
     *
     * @param array<string, string> $contentHashes
     */
    private function compileExtensions(string $cacheDir, OutputInterface $output, array &$contentHashes): ?ArtifactEntry
    {
        $container = $this->kernel->container();

        if (!$container->has(ExtensionBootstrap::class)) {
            $output->writeln('  Extensions: skipped (no extension bootstrap)');

            return null;
        }

        /** @var ExtensionBootstrap $bootstrap */
        $bootstrap = $container->get(ExtensionBootstrap::class);

        $manifests = array_values($bootstrap->registry->allManifests());

        if ($manifests === []) {
            $output->writeln('  Extensions: skipped (no extensions registered)');

            return null;
        }

        $compiler = new ExtensionGraphCompiler();
        $compiled = $compiler->compile($manifests);
        $content = $compiler->export($compiled);

        $contentHashes['extension_code'] = $compiled->totalHash;

        $path = 'extensions.manifest.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln(sprintf('  Extensions: %d extension(s) compiled', count($compiled->extensions)));

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Compile event listener map.
     *
     * @param array<string, string> $contentHashes
     */
    private function compileEventMap(string $cacheDir, OutputInterface $output, array &$contentHashes): ?ArtifactEntry
    {
        $container = $this->kernel->container();

        if (!$container->has(ListenerProvider::class)) {
            $output->writeln('  Events: skipped (no listener provider)');

            return null;
        }

        /** @var ListenerProvider $provider */
        $provider = $container->get(ListenerProvider::class);

        $compiler = new EventMapCompiler();
        $compiledMap = $compiler->compile($provider);

        if ($compiledMap === []) {
            $output->writeln('  Events: no listeners registered');

            return null;
        }

        $content = $compiler->export($compiledMap);
        $contentHashes['event_listeners'] = hash('sha256', $content);

        $path = 'events_map.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln(sprintf('  Events: %d event type(s) compiled', count($compiledMap)));

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Compile i18n catalog index.
     *
     * Reads the catalog path from the typed I18nConfig DTO in the
     * ConfigRepository, matching how I18nWiring resolves it.
     *
     * @param array<string, string> $contentHashes
     */
    private function compileI18n(string $cacheDir, OutputInterface $output, array &$contentHashes): ?ArtifactEntry
    {
        $repository = $this->getConfigRepository();

        if ($repository === null || !$repository->has(I18nConfig::class)) {
            $output->writeln('  i18n: skipped (no i18n config)');

            return null;
        }

        $i18nConfig = $repository->get(I18nConfig::class);
        $catalogPath = $i18nConfig->catalogPath;

        if ($catalogPath === null || !is_dir($catalogPath)) {
            $output->writeln('  i18n: skipped (no catalog path configured)');

            return null;
        }

        $compiler = new I18nCatalogCompiler();
        $catalogIndex = $compiler->compile($catalogPath);

        if ($catalogIndex->locales === []) {
            $output->writeln('  i18n: skipped (no locales found)');

            return null;
        }

        $content = $compiler->export($catalogIndex);
        $contentHashes['i18n_catalog'] = $catalogIndex->totalHash;

        $path = 'i18n_catalog_index.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln(sprintf(
            '  i18n: %d locale(s), %d domain(s) compiled',
            count($catalogIndex->locales),
            $this->countDomains($catalogIndex->index),
        ));

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Generate OPcache preload file.
     */
    private function generatePreload(string $cacheDir, OutputInterface $output, RuntimeType $runtime): ?ArtifactEntry
    {
        $configPath = $this->kernel->configManager()?->configPath();

        if ($configPath === null) {
            $output->writeln('  Preload: skipped (no base path)');

            return null;
        }

        // Derive base path from config path (config/ -> project root)
        $basePath = $configPath . DIRECTORY_SEPARATOR . '..';

        $generator = new PreloadGenerator();
        $content = $generator->generate($runtime, $basePath, $cacheDir);

        $path = 'preload.php';
        $this->atomicWrite($cacheDir . DIRECTORY_SEPARATOR . $path, $content);

        $output->writeln(sprintf('  Preload: generated (%s runtime)', $runtime->value));

        return new ArtifactEntry(
            path: $path,
            hash: hash('sha256', $content),
            size: strlen($content),
        );
    }

    /**
     * Sign the build manifest using the central Keyring.
     */
    private function signManifest(BuildManifest $manifest, OutputInterface $output): BuildManifest
    {
        $container = $this->kernel->container();

        if (!$container->has(HmacInterface::class) || !$container->has(KeyProviderInterface::class)) {
            $output->warning('  Signing skipped: HMAC or KeyProvider not available.');

            return $manifest;
        }

        /** @var HmacInterface $hmac */
        $hmac = $container->get(HmacInterface::class);

        /** @var KeyProviderInterface $keyProvider */
        $keyProvider = $container->get(KeyProviderInterface::class);

        $verifier = new ArtifactIntegrityVerifier();
        $signature = $verifier->sign($manifest, $hmac, $keyProvider);

        return new BuildManifest(
            version: $manifest->version,
            algorithm: $manifest->algorithm,
            artifacts: $manifest->artifacts,
            contentHashes: $manifest->contentHashes,
            signature: $signature,
        );
    }

    /**
     * Write content atomically: write to .tmp, then rename.
     */
    private function atomicWrite(string $path, string $content): void
    {
        $tmpPath = $path . '.tmp';
        $this->tmpFiles[] = $tmpPath;

        $written = file_put_contents($tmpPath, $content);

        if ($written === false) {
            throw BuildException::artifactWriteFailed($path, 'file_put_contents failed');
        }

        $renamed = rename($tmpPath, $path);

        if (!$renamed) {
            throw BuildException::atomicWriteFailed($tmpPath, $path);
        }

        // Remove from cleanup list on success
        $this->tmpFiles = array_values(array_filter(
            $this->tmpFiles,
            static fn(string $f): bool => $f !== $tmpPath,
        ));
    }

    /**
     * Clean up any leftover .tmp files on build failure.
     */
    private function cleanupTmpFiles(): void
    {
        foreach ($this->tmpFiles as $tmpFile) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        $this->tmpFiles = [];
    }

    private function getConfigRepository(): ?ConfigRepository
    {
        return $this->kernel->configManager()?->repository();
    }

    private function resolveCacheDir(): ?string
    {
        $configManager = $this->kernel->configManager();
        $configPath = $configManager?->configPath();

        if ($configPath === null) {
            return null;
        }

        return $configPath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    /**
     * Count total domains across all locales.
     *
     * @param array<string, array<string, list<string>>> $index
     */
    private function countDomains(array $index): int
    {
        $domains = [];

        foreach ($index as $localeDomains) {
            foreach (array_keys($localeDomains) as $domain) {
                $domains[$domain] = true;
            }
        }

        return count($domains);
    }
}
