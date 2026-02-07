<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use function extension_loaded;

use const FILTER_VALIDATE_IP;

use function filter_var;
use function in_array;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Kernel;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\PersistentRuntime;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\Upgrade\UpgradeContext;
use Pulsar\Studio\Console\Collector\InstrumentedRuntime;
use Pulsar\Studio\FiberScopedContextProvider;

use function sprintf;

/**
 * Start the persistent HTTP runtime server.
 */
final class RuntimeServeCommand extends Command
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly ?RuntimeConfig $runtimeConfig = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MetricRegistry $metricRegistry = null,
        private readonly ?RequestResetRegistry $resetRegistry = null,
        private readonly ?FiberScopedContextProvider $contextProvider = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'runtime:serve';
        $this->description = 'Start the persistent HTTP runtime server';

        $this->addOption('host', 'Address to bind', null, null);
        $this->addOption('port', 'Port to listen on', null, null);
        $this->addOption('max-requests', 'Maximum requests before recycling', null, null);
        $this->addOption('memory', 'Memory threshold in MB', null, null);
        $this->addOption('timeout', 'Time limit in seconds', null, null);
        $this->addOption('concurrency', 'Fiber concurrency (0 = synchronous)', null, null);
        $this->addOption('public', 'Allow binding to non-loopback address');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Early check: ext-sockets must be loaded
        if (!extension_loaded('sockets')) {
            $output->error(
                'The "sockets" PHP extension is required for the persistent runtime. '
                . 'Install or enable it in php.ini.',
            );

            return ExitCode::Error->value;
        }

        $config = $this->resolveConfig($input);
        $isPublic = $input->getOption('public') !== null;

        // Validate host binding
        if (!$this->validateHost($config->host, $isPublic, $output)) {
            return ExitCode::Error->value;
        }

        // Validate port range
        if ($config->port < 1 || $config->port > 65535) {
            $output->error(sprintf('Invalid port: %d (must be 1-65535)', $config->port));

            return ExitCode::Error->value;
        }

        $output->info(sprintf(
            'Starting persistent runtime on %s:%d (concurrency: %s, max-requests: %d, memory: %dMB)',
            $config->host,
            $config->port,
            $config->fiberConcurrency > 0 ? (string) $config->fiberConcurrency : 'sync',
            $config->maxRequests,
            $config->memoryThresholdMb,
        ));

        // Build runtime dependencies
        $registry = $this->resetRegistry ?? new RequestResetRegistry();
        $leakDetector = new LeakDetector(logger: $this->logger);
        $sandbox = new RequestSandbox(
            $this->kernel->container(),
            $registry,
            $leakDetector,
        );

        $collector = null;

        if ($this->metricRegistry !== null && $this->contextProvider !== null) {
            $collector = new InstrumentedRuntime(
                inner: new \Pulsar\Runtime\FpmRuntime($this->kernel),
                contextProvider: $this->contextProvider,
                metricRegistry: $this->metricRegistry,
                emit: static function (): void {},
            );
        }

        $upgradeContext = new UpgradeContext(
            logger: $this->logger,
            metrics: $this->metricRegistry,
            config: $config,
        );

        $runtime = new PersistentRuntime(
            kernel: $this->kernel,
            sandbox: $sandbox,
            config: $config,
            logger: $this->logger,
            collector: $collector,
            upgradeContext: $upgradeContext,
        );

        $runtime->start();

        $output->success('Runtime stopped gracefully.');

        return ExitCode::Success->value;
    }

    private function resolveConfig(InputInterface $input): RuntimeConfig
    {
        $base = $this->runtimeConfig ?? new RuntimeConfig();

        /** @var string|null $host */
        $host = $input->getOption('host');
        /** @var string|null $port */
        $port = $input->getOption('port');
        /** @var string|null $maxRequests */
        $maxRequests = $input->getOption('max-requests');
        /** @var string|null $memory */
        $memory = $input->getOption('memory');
        /** @var string|null $timeout */
        $timeout = $input->getOption('timeout');
        /** @var string|null $concurrency */
        $concurrency = $input->getOption('concurrency');

        return new RuntimeConfig(
            host: $host ?? $base->host,
            port: $port !== null ? (int) $port : $base->port,
            maxRequests: $maxRequests !== null ? (int) $maxRequests : $base->maxRequests,
            memoryThresholdMb: $memory !== null ? (int) $memory : $base->memoryThresholdMb,
            timeLimitSeconds: $timeout !== null ? (int) $timeout : $base->timeLimitSeconds,
            keepAlive: $base->keepAlive,
            keepAliveTimeout: $base->keepAliveTimeout,
            headerTimeoutSeconds: $base->headerTimeoutSeconds,
            bodyTimeoutSeconds: $base->bodyTimeoutSeconds,
            fiberConcurrency: $concurrency !== null ? (int) $concurrency : $base->fiberConcurrency,
            maxHeaderSize: $base->maxHeaderSize,
            maxBodySize: $base->maxBodySize,
            addDateHeader: $base->addDateHeader,
        );
    }

    private function validateHost(string $host, bool $isPublic, OutputInterface $output): bool
    {
        // Loopback addresses don't need --public
        $loopbackEquivalents = ['127.0.0.1', '::1', 'localhost', 'localhost.'];

        if (in_array($host, $loopbackEquivalents, true)) {
            return true;
        }

        // If it's an IP literal, check if loopback
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($isIp && !$isPublic) {
            $output->error(sprintf(
                'Binding to non-loopback address "%s" requires the --public flag. '
                . 'This server is not a TLS endpoint. Use --public to acknowledge '
                . 'binding to a non-loopback address, and run behind a reverse proxy in production.',
                $host,
            ));

            return false;
        }

        // Non-IP hostname (could resolve to anything)
        if (!$isIp && !$isPublic) {
            $output->error(sprintf(
                'Binding to hostname "%s" requires the --public flag because hostname '
                . 'resolution may bind to a non-loopback address. Use --public to acknowledge this.',
                $host,
            ));

            return false;
        }

        return true;
    }
}
