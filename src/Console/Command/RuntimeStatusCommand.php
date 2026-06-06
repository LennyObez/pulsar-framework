<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Runtime\RuntimeResolver;
use Pulsar\Runtime\RuntimeType;

use function in_array;
use function sprintf;

/**
 * Show available runtimes and current configuration.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class RuntimeStatusCommand extends Command
{
    public function __construct(
        private readonly RuntimeResolver $resolver,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'runtime:status';
        $this->description = 'Show available runtimes and current configuration';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $available = $this->resolver->available();
        $resolved = $this->resolver->resolve();

        $output->info('Runtime Status');
        $output->writeln();

        foreach (RuntimeType::cases() as $type) {
            $isAvailable = in_array($type, $available, true);
            $isActive = $type === $resolved;
            $status = $isAvailable ? 'available' : 'not available';
            $marker = $isActive ? ' (active)' : '';

            $output->writeln(sprintf(
                '  %s: %s%s',
                $type->value,
                $status,
                $marker,
            ));
        }

        $output->writeln();
        $output->writeln(sprintf('Resolved runtime: %s', $resolved->value));

        return ExitCode::Success->value;
    }
}
