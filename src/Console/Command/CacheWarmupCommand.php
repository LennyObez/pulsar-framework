<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Console\Command;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\KernelInterface;

/**
 * Alias for `optimize`: discoverable as `cache:warmup`.
 *
 * Delegates entirely to OptimizeCommand with the same options.
 */
#[Internal]
final class CacheWarmupCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?FrameworkCacheInterface $frameworkCache = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'cache:warmup';
        $this->description = 'Warm config, route, and container caches (alias for optimize)';
        $this->addOption('strict', 'Fail if any closure-based route is detected', 's');
        $this->addOption('encrypt', 'Encrypt cache payloads at rest', 'e');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        return new OptimizeCommand($this->kernel, $this->frameworkCache)
            ->execute($input, $output);
    }
}
