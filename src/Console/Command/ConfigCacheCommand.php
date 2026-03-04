<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Cache\ConfigCache;
use Pulsar\Config\ConfigRepository;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Throwable;

use function sprintf;

/**
 * Compile configuration to a cached file for production.
 *
 * Serializes the entire ConfigRepository into a single binary envelope,
 * optionally encrypted. Run during deployment to eliminate config
 * file parsing and DTO construction overhead on every request.
 */
final class ConfigCacheCommand extends Command
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConfigCache $cache,
        private readonly string $cachePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'config:cache';
        $this->description = 'Compile configuration to a cached file for production';

        $this->addOption('encrypt', 'Encrypt the cache file', 'e');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $encrypt = $input->hasOption('encrypt');

        try {
            $this->cache->write($this->cachePath, $this->config, $encrypt);
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to cache configuration: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $output->success('Configuration cached successfully.');

        return ExitCode::Success->value;
    }
}
