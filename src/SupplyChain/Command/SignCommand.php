<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\SupplyChain\Signing\ArtifactSigner;
use Pulsar\SupplyChain\Signing\ReleaseSignerService;
use Pulsar\SupplyChain\Signing\SignatureManifestSerializer;
use RuntimeException;

use function count;
use function file_get_contents;
use function file_put_contents;
use function is_string;
use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;

/**
 * CLI command to sign release artifacts with Ed25519.
 */
#[Internal(reason: 'CLI command registration')]
final class SignCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supply-chain:sign';
        $this->description = 'Sign release artifacts with Ed25519';

        $this->addOption('key-file', 'Path to Ed25519 secret key file (raw 64 bytes)', 'k');
        $this->addOption('dir', 'Directory containing artifacts to sign (default: dist/)');
        $this->addOption('output', 'Output manifest file path (default: signatures.json)', 'o');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyFile = $input->getStringOption('key-file', '');

        if ($keyFile === '') {
            $output->error('--key-file is required. Provide the path to your Ed25519 secret key.');

            return ExitCode::Error->value;
        }

        $secretKey = file_get_contents($keyFile);

        if (!is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $output->error(sprintf(
                'Invalid key file: expected %d raw bytes.',
                SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
            ));

            return ExitCode::Error->value;
        }

        $dir = $input->getStringOption('dir', $this->projectRoot . '/dist');
        $outputPath = $input->getStringOption('output', $this->projectRoot . '/signatures.json');

        $output->info(sprintf('Signing artifacts in %s...', $dir));

        try {
            $service = new ReleaseSignerService(new ArtifactSigner());
            $manifests = $service->signDirectory($dir, $secretKey);

            if ($manifests === []) {
                $output->warning('No release artifacts found to sign.');

                return ExitCode::Success->value;
            }

            $serializer = new SignatureManifestSerializer();
            $json = $serializer->serialize($manifests);
            file_put_contents($outputPath, $json);

            $output->success(sprintf(
                'Signed %d artifact(s). Manifest written to %s',
                count($manifests),
                $outputPath,
            ));

            return ExitCode::Success->value;
        } catch (RuntimeException $e) {
            $output->error($e->getMessage());

            return ExitCode::Error->value;
        }
    }
}
