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
 * Store a secret in the encrypted vault.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
final class SecretSetCommand extends Command
{
    public function __construct(
        private readonly SecretVault $vault,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'secret:set';
        $this->description = 'Store a secret in the encrypted vault';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $key */
        $key = $input->getArgument('key');
        /** @var string|null $value */
        $value = $input->getArgument('value');

        if ($key === null || $value === null) {
            $output->errorln('Usage: secret:set <key> <value>');

            return ExitCode::Error->value;
        }

        try {
            $this->vault->set($key, $value);
        } catch (Throwable $e) {
            $output->errorln(sprintf('Failed to set secret: %s', $e->getMessage()));

            return ExitCode::Error->value;
        }

        $output->success(sprintf('Secret "%s" stored successfully.', $key));

        return ExitCode::Success->value;
    }
}
