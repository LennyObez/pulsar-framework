<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Internal\McpToolRegistry;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;
use Pulsar\Extension\McpServer\Internal\Redaction\McpRedactionPipeline;
use Pulsar\Extension\McpServer\Internal\Security\McpAccessGate;
use Pulsar\Extension\McpServer\Internal\Security\ToolPermissionChecker;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;
use Pulsar\Extension\McpServer\Internal\Tools\ReadApiSnapshotTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadArchitectureMapTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadCommandsTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadConfigSchemaTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadContainerBindingsTool;
use Pulsar\Extension\McpServer\Internal\Tools\ReadRoutesTool;
use Pulsar\Extension\McpServer\Internal\Tools\RunAnalysisTool;
use Pulsar\Extension\McpServer\Internal\Tools\RunFormatterTool;
use Pulsar\Extension\McpServer\Internal\Tools\RunTestsTool;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\RouterInterface;

use function dirname;
use function getcwd;
use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * MCP server extension: exposes project metadata and developer tools
 * to AI assistants via the Model Context Protocol (JSON-RPC 2.0 over stdio).
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
final class McpServerExtension implements ExtensionInterface, PreBootExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/mcp-server';
    }

    public function register(ContainerInterface $container): void
    {
        // Registration deferred to preBoot after config is loaded
    }

    public function preBoot(ContainerInterface $container): void
    {
        /** @var ConfigManagerInterface $configManager */
        $configManager = $container->get(ConfigManagerInterface::class);
        $environment = $configManager->environment();
        $configPath = $configManager->configPath();

        // Load MCP config
        $configData = [];
        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'mcp.php')) {
            /**
             * @var mixed $loaded
             */
            $loaded = require $configPath . DIRECTORY_SEPARATOR . 'mcp.php';
            if (is_array($loaded)) {
                /** @var array<string, mixed> $loaded */
                $configData = $loaded;
            }
        }

        $config = McpConfig::fromArray($configData, $environment);
        $container->instance(McpConfig::class, $config);

        if (!$config->enabled) {
            return;
        }

        /** @var AppConfig $appConfig */
        $appConfig = $configManager->repository()->get(AppConfig::class);

        // Environment gating
        $projectRoot = $config->projectRoot !== ''
            ? $config->projectRoot
            : ($configPath !== null ? dirname($configPath) : (getcwd() ?: '.'));

        // Build security infrastructure
        $scrubber = $container->has(SensitiveDataScrubber::class)
            ? $container->get(SensitiveDataScrubber::class)
            : new SensitiveDataScrubber();
        /** @var SensitiveDataScrubber $scrubber */

        $knownSecrets = McpRedactionPipeline::collectKnownSecrets($environment);
        $redactionPipeline = new McpRedactionPipeline($scrubber, $knownSecrets);
        $container->instance(McpRedactionPipelineInterface::class, $redactionPipeline);

        $accessGate = new McpAccessGate(
            securityConfig: $config->security,
            mode: $appConfig->mode,
            environment: $environment,
            projectRoot: $projectRoot,
        );
        $container->instance(McpAccessGateInterface::class, $accessGate);

        // Build tool registry
        $registry = new McpToolRegistry();

        // Register read tools (if ProjectMetadataService is available)
        if ($container->has(ProjectMetadataService::class)) {
            /** @var ProjectMetadataService $metadataService */
            $metadataService = $container->get(ProjectMetadataService::class);

            $registry->register(new ReadApiSnapshotTool($metadataService));
            $registry->register(new ReadArchitectureMapTool($metadataService));
            $registry->register(new ReadConfigSchemaTool($metadataService));
            $registry->register(new ReadRoutesTool($metadataService));
            $registry->register(new ReadCommandsTool($metadataService));
            $registry->register(new ReadContainerBindingsTool($metadataService));
        }

        // Register action tools
        $subprocessRunner = new SubprocessRunner(
            projectRoot: $projectRoot,
            timeout: $config->tools->actionTimeout,
            maxOutputBytes: $config->tools->maxOutputBytes,
            redactionPipeline: $redactionPipeline,
        );

        $phpunitBin = $config->tools->commands['phpunit'] ?? $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        $composerBin = $config->tools->commands['composer'] ?? 'composer';
        $pnpmBin = $config->tools->commands['pnpm'] ?? 'pnpm';

        $registry->register(new RunTestsTool($subprocessRunner, $accessGate, $phpunitBin, $projectRoot));
        $registry->register(new RunFormatterTool($subprocessRunner, $accessGate, $composerBin, $pnpmBin, $projectRoot));
        $registry->register(new RunAnalysisTool($subprocessRunner, $accessGate, $composerBin));

        $container->instance(McpToolRegistryInterface::class, $registry);

        // Permission checker
        $permissionChecker = new ToolPermissionChecker($config->tools, $registry);
        $container->instance(ToolPermissionCheckerInterface::class, $permissionChecker);

        // Rate limiter (optional)
        $rateLimiter = $container->has(RateLimiterInterface::class)
            ? $container->get(RateLimiterInterface::class)
            : null;
        /** @var RateLimiterInterface|null $rateLimiter */

        // Audit logger (optional)
        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;
        /** @var AuditLoggerInterface|null $auditLogger */

        // Message handler
        $messageHandler = new MessageHandler(
            registry: $registry,
            permissionChecker: $permissionChecker,
            redactionPipeline: $redactionPipeline,
            auditLogger: $auditLogger,
            rateLimiter: $rateLimiter,
            config: $config,
            scrubber: $scrubber,
        );
        $container->instance(MessageHandler::class, $messageHandler);

        // Stdio transport
        $transport = new StdioTransport();
        $container->instance(StdioTransport::class, $transport);

        // Command (resolvable from manifest-driven registration)
        $container->instance(
            Console\McpServeCommand::class,
            new Console\McpServeCommand($messageHandler, $transport, $accessGate),
        );
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // MCP uses stdio transport: no HTTP routes
    }

    public function providers(): array
    {
        return [McpServerServiceProvider::class];
    }
}
