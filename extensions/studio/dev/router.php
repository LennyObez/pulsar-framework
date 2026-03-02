<?php

declare(strict_types=1);

/**
 * Router script for the Studio development server (php -S).
 *
 * Studio has its own lightweight router (StudioRouter) rather than
 * the full framework kernel. Static asset serving and translation
 * bootstrap are shared via DevServerBootstrap.
 *
 * Usage: php -S host:port -t extensions/studio/dev/public/ extensions/studio/dev/router.php
 */

// Find project root by locating vendor/autoload.php
$dir = __DIR__;
while ($dir !== dirname($dir)) {
    if (file_exists($dir . '/vendor/autoload.php')) {
        break;
    }
    $dir = dirname($dir);
}

require $dir . '/vendor/autoload.php';

use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\I18nConfig;
use Pulsar\Dev\DevServerBootstrap;
use Pulsar\Dev\DevServerConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Extension\Studio\Server\Controller\ActivityLogController;
use Pulsar\Extension\Studio\Server\Controller\ApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkController;
use Pulsar\Extension\Studio\Server\Controller\ConsoleOverviewController;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Extension\Studio\Server\Controller\DeploymentController;
use Pulsar\Extension\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Extension\Studio\Server\Controller\HealthDashboardController;
use Pulsar\Extension\Studio\Server\Controller\LandingController;
use Pulsar\Extension\Studio\Server\Controller\LogExplorerController;
use Pulsar\Extension\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;
use Pulsar\Extension\Studio\Server\StudioRouter;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\Catalog\ChainCatalog;
use Pulsar\I18n\Catalog\PhpCatalog;
use Pulsar\I18n\Format\FallbackMessageFormatter;
use Pulsar\I18n\Format\IcuMessageFormatter;
use Pulsar\I18n\Translator;
use Pulsar\Extension\Studio\Internal\Diagnostics\GitLogReader;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;

$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// 1. Serve static assets (shared with all dev routers)
$studioConfig = new DevServerConfig(
    extensionName: 'studio',
    assetPrefixes: [
        '/studio/assets/' => [
            'extensions/studio/frontend/styles',
            'extensions/studio/frontend/dist',
        ],
    ],
);

if (DevServerBootstrap::serveStaticAsset($dir, $path, $studioConfig)) {
    return;
}

// 2. Short-circuit browser probes
if (str_starts_with($path, '/.well-known/') || $path === '/favicon.ico') {
    http_response_code(404);

    return;
}

// 3. Bootstrap translations so __() works in Studio templates
$catalogPaths = [];
$coreLangPath = $dir . '/resources/lang';

if (is_dir($coreLangPath)) {
    $catalogPaths[] = new PhpCatalog($coreLangPath);
}

$studioLangPath = $dir . '/extensions/studio/resources/lang';

if (is_dir($studioLangPath)) {
    $catalogPaths[] = new PhpCatalog($studioLangPath);
}

if ($catalogPaths !== []) {
    $i18nConfig = new I18nConfig(
        defaultLocale: 'en',
        supportedLocales: ['en'],
        fallbackLocales: ['en'],
        catalogPath: null,
        regulated: false,
        maxSupportedLocales: 50,
        strictMode: false,
    );

    $formatter = extension_loaded('intl')
        ? new IcuMessageFormatter()
        : new FallbackMessageFormatter();

    $translator = new Translator(new ChainCatalog(...$catalogPaths), $i18nConfig, $formatter);
    Translator::setGlobalInstance($translator);
}

// 4. Bootstrap Studio
$envFile = file_exists($dir . '/.env') ? $dir . '/.env' : null;
$environment = Environment::load($envFile);

$configPath = $dir . '/config/studio.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Studio config not found. Create config/studio.php to use Studio.';

    return;
}

/** @var array<string, mixed> $configData */
$configData = require $configPath;
$config = StudioConfig::fromArray($configData, $environment);

if (!$config->enabled) {
    http_response_code(503);
    echo 'Studio is disabled. Set enabled: true in config/studio.php.';

    return;
}

// Create event store
$storagePath = str_starts_with($config->storagePath, '/')
    ? $config->storagePath
    : $dir . '/' . $config->storagePath;

$storageDir = dirname($storagePath);
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0o755, true);
}

$sqliteStore = new SqliteEventStore($storagePath);

// Wrap with encryption if PULSAR_MASTER_KEY is set
$store = $sqliteStore;
$masterKeyHex = $environment->get('PULSAR_MASTER_KEY') ?? '';
if ($masterKeyHex !== '') {
    try {
        $masterKey = MasterKey::fromHex($masterKeyHex);
        $studioEncryptor = Encryptor::fromDerivedKey($masterKey, 3, 'stud_enc');
        $store = new EncryptedEventStore($sqliteStore, $studioEncryptor);
    } catch (Throwable) {
        // Invalid key or sodium failure: use plain store
    }
}

// Create dependencies
$aggregator = new DashboardAggregator($store);
$timelineBuilder = new TimelineBuilder($store);

$envMode = EnvironmentMode::tryFrom($environment->get('APP_ENV') ?? 'local') ?? EnvironmentMode::Local;
$safetyMode = new ProductionSafetyMode($envMode);

// Create controllers
$landing = new LandingController($config, $store);
$consoleOverview = new ConsoleOverviewController($aggregator);
$requestExplorer = new RequestExplorerController($store);
$databaseExplorer = new DatabaseExplorerController($store);
$logExplorer = new LogExplorerController($store);
$exceptionExplorer = new ExceptionExplorerController($store, $safetyMode);
$timeline = new TimelineController($timelineBuilder);
$api = new ApiController($store);
$benchmarkController = new BenchmarkController($aggregator);
$benchmarkApi = new BenchmarkApiController($store, $aggregator, $dir);
$activityLog = new ActivityLogController($store);
$healthDashboard = new HealthDashboardController($store);
$gitLog = new class () implements GitLogReader {
    #[\Override]
    public function getVersionTags(int $limit = 20): array
    {
        return [];
    }

    #[\Override]
    public function getCurrentRef(): string
    {
        return 'dev';
    }

    #[\Override]
    public function getCommitsBetween(?string $from, string $to, int $limit = 50): array
    {
        return [];
    }

    #[\Override]
    public function getDiffStats(string $from, string $to): array
    {
        return ['files_changed' => 0, 'insertions' => 0, 'deletions' => 0];
    }
};
$deployment = new DeploymentController($gitLog);

// Create router and dispatch
$router = new StudioRouter(
    landing: $landing,
    consoleOverview: $consoleOverview,
    requestExplorer: $requestExplorer,
    databaseExplorer: $databaseExplorer,
    logExplorer: $logExplorer,
    exceptionExplorer: $exceptionExplorer,
    timeline: $timeline,
    api: $api,
    benchmark: $benchmarkController,
    benchmarkApi: $benchmarkApi,
    activityLog: $activityLog,
    healthDashboard: $healthDashboard,
    deployment: $deployment,
    safetyMode: $safetyMode,
);

// Build request with query attributes
/** @var array<string, mixed> $queryParams */
$queryParams = $_GET;
$request = ServerRequest::fromGlobals();

foreach ($queryParams as $key => $value) {
    if (is_string($value)) {
        $request = $request->withAttribute('_query_' . $key, $value);
    }
}

try {
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    error_log(sprintf(
        '[Studio] %s %s - 500 Internal Server Error: %s',
        is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
        $requestUri,
        $e->getMessage(),
    ));
    http_response_code(500);
    echo 'Internal Server Error: ' . $e->getMessage();

    return;
}

// Log and emit
/** @var int $remotePort */
$remotePort = $_SERVER['REMOTE_PORT'] ?? 0;
error_log(sprintf(
    '[%d]: %s %s - %d',
    $remotePort,
    is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
    $requestUri,
    $response->getStatusCode(),
));

DevServerBootstrap::emitResponse($response);
