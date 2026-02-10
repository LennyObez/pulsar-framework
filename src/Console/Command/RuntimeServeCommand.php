<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Runtime\RuntimeCollectorInterface;
use Pulsar\Runtime\RuntimeFactory;
use Pulsar\Runtime\RuntimeResolver;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Upgrade\UpgradeContext;

use function extension_loaded;
use function filter_var;
use function in_array;
use function sprintf;

use const FILTER_VALIDATE_IP;

/**
 * Start the HTTP runtime server.
 */
final class RuntimeServeCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RuntimeFactory $runtimeFactory,
        private readonly RuntimeResolver $resolver,
        private readonly ?RuntimeConfig $runtimeConfig = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MetricRegistry $metricRegistry = null,
        private readonly ?RuntimeCollectorInterface $collector = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'runtime:serve';
        $this->description = 'Start the HTTP runtime server';

        $this->addOption('host', 'Address to bind');
        $this->addOption('port', 'Port to listen on');
        $this->addOption('max-requests', 'Maximum requests before recycling');
        $this->addOption('memory', 'Memory threshold in MB');
        $this->addOption('timeout', 'Time limit in seconds');
        $this->addOption('concurrency', 'Fiber concurrency (0 = synchronous)');
        $this->addOption('public', 'Allow binding to non-loopback address');
        $this->addOption('runtime', 'Runtime type (fpm, persistent, frankenphp, roadrunner)');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->resolveConfig($input);

        // Resolve runtime type from --runtime option or auto-detect
        $runtimeOption = $input->getOption('runtime');
        /** @var string|null $runtimeOption */
        $runtimeType = $runtimeOption !== null
            ? RuntimeType::from($runtimeOption)
            : $this->resolver->resolve();

        // ext-sockets check only applies to the persistent runtime
        if ($runtimeType === RuntimeType::Persistent && !extension_loaded('sockets')) {
            $output->error(
                'The "sockets" PHP extension is required for the persistent runtime. '
                . 'Install or enable it in php.ini.',
            );

            return ExitCode::Error->value;
        }

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
            'Starting %s runtime on %s:%d (concurrency: %s, max-requests: %d, memory: %dMB)',
            $runtimeType->value,
            $config->host,
            $config->port,
            $config->fiberConcurrency > 0 ? (string) $config->fiberConcurrency : 'sync',
            $config->maxRequests,
            $config->memoryThresholdMb,
        ));

        // Build runtime via factory (encapsulates internal runtime dependencies)
        $upgradeContext = new UpgradeContext(
            logger: $this->logger,
            metrics: $this->metricRegistry,
            config: $config,
        );

        $runtime = $this->runtimeFactory->createForType(
            type: $runtimeType,
            kernel: $this->kernel,
            config: $config,
            logger: $this->logger,
            collector: $this->collector,
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
