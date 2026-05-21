<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Security\Vault\SecretVault;
use Throwable;

use function count;
use function sprintf;

/**
 * List all secret keys stored in the vault (without revealing values).
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class SecretListCommand extends Command
{
    public function __construct(
        private readonly SecretVault $vault,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'secret:list';
        $this->description = 'List all secret keys in the vault';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $keys = $this->vault->keys();
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to list secrets: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        if ($keys === []) {
            $output->info('The vault is empty.');

            return ExitCode::Success->value;
        }

        $output->writeln('Secrets in vault:');
        $output->newLine();

        foreach ($keys as $key) {
            $output->writeln(sprintf('  - %s', $key));
        }

        $output->newLine();
        $output->info(sprintf('%d secret(s) stored.', count($keys)));

        return ExitCode::Success->value;
    }
}
