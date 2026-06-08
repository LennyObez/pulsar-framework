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

use function sprintf;

/**
 * Retrieve and display a decrypted secret from the vault.
 */
final class SecretGetCommand extends Command
{
    public function __construct(
        private readonly SecretVault $vault,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'secret:get';
        $this->description = 'Retrieve a decrypted secret from the vault';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $key */
        $key = $input->getArgument('key');

        if ($key === null) {
            $output->errorln('Usage: secret:get <key>');

            return ExitCode::Error->value;
        }

        try {
            $value = $this->vault->get($key);
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to get secret: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $output->writeln($value);

        return ExitCode::Success->value;
    }
}
