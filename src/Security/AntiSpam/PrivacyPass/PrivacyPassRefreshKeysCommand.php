<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivacyPassDirectoryClient;

use function count;
use function sprintf;

/**
 * Fetches the Privacy Pass issuer directory (RFC 9576) and caches its token
 * keys, so the request path can verify tokens without a runtime network call.
 *
 * Run on a schedule (cron / the framework scheduler) to keep the keys fresh:
 * the verifier reads the cached keys at boot.
 */
#[Internal(reason: 'CLI command implementation')]
final class PrivacyPassRefreshKeysCommand extends Command
{
    public function __construct(
        private readonly PrivacyPassDirectoryClient $client,
        private readonly string $directoryUrl,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'privacy-pass:keys:refresh';
        $this->description = 'Fetch and cache the Privacy Pass issuer directory token keys';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = $this->client->refresh($this->directoryUrl);

        if ($keys === []) {
            $output->errorln('No Privacy Pass token keys were retrieved from the issuer directory (see logs).');

            return ExitCode::Error->value;
        }

        $output->success(sprintf('Cached %d Privacy Pass token key(s) from the issuer directory.', count($keys)));

        return ExitCode::Success->value;
    }
}
