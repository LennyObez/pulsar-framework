<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Command;

use Override;
use Pulsar\Api\Api;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Server\GrpcServerInterface;
use RuntimeException;

use function is_string;
use function php_sapi_name;
use function sprintf;

/**
 * Starts the gRPC server.
 *
 * Validates that the runtime is persistent (not FPM) before starting.
 * Supports overriding host, port, workers, and TLS settings via options.
 */
#[Api(since: '1.0.0')]
final class ServeCommand extends Command
{
    public function __construct(
        private readonly GrpcServerInterface $server,
        private readonly GrpcConfig $config,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'grpc:serve';
        $this->description = 'Start the gRPC server';

        $this->addOption('host', 'Bind address', 'H', $this->config->host);
        $this->addOption('port', 'Bind port', 'p', (string) $this->config->port);
        $this->addOption('workers', 'Number of worker processes', 'w', (string) $this->config->maxWorkers);
        $this->addOption('tls-cert', 'Path to TLS certificate file');
        $this->addOption('tls-key', 'Path to TLS private key file');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $sapi = php_sapi_name();

        if ($sapi === 'fpm-fcgi') {
            $output->errorln(
                'The gRPC server requires a persistent runtime (RoadRunner, FrankenPHP, or CLI).',
            );
            $output->errorln(
                'PHP-FPM cannot maintain the HTTP/2 connections required by gRPC.',
            );
            $output->errorln(
                'Set RUNTIME_DRIVER=roadrunner or RUNTIME_DRIVER=frankenphp in your environment.',
            );

            return ExitCode::Error->value;
        }

        $host = $this->resolveOption($input, 'host', $this->config->host);
        $port = (int) $this->resolveOption($input, 'port', (string) $this->config->port);

        $output->info(sprintf('Starting gRPC server on %s:%d', $host, $port));

        if ($this->config->tls->enabled) {
            $output->info('TLS enabled');

            if ($this->config->tls->isMutual()) {
                $output->info('Mutual TLS (mTLS) enabled — client certificates required');
            }
        }

        $output->info(sprintf('Adapter: %s', $this->config->adapter));
        $output->writeln('');

        try {
            $this->server->start();
        } catch (RuntimeException $e) {
            $output->errorln(sprintf('Failed to start gRPC server: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        return ExitCode::Success->value;
    }

    private function resolveOption(InputInterface $input, string $name, string $default): string
    {
        if ($input->hasOption($name)) {
            $value = $input->getOption($name);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}
