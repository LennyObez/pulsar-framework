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
use function is_string;
use function sprintf;
use function strlen;

use const SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;

/**
 * CLI command to verify release artifact signatures.
 */
#[Internal(reason: 'CLI command registration')]
final class VerifyCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'supply-chain:verify';
        $this->description = 'Verify release artifact Ed25519 signatures';

        $this->addOption('key-file', 'Path to Ed25519 public key file (raw 32 bytes)', 'k');
        $this->addOption('dir', 'Directory containing artifacts to verify (default: dist/)');
        $this->addOption('manifest', 'Path to signatures manifest (default: signatures.json)', 'm');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyFile = $input->getStringOption('key-file', '');

        if ($keyFile === '') {
            $output->error('--key-file is required. Provide the path to your Ed25519 public key.');

            return ExitCode::Error->value;
        }

        $publicKey = file_get_contents($keyFile);

        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $output->error(sprintf(
                'Invalid key file: expected %d raw bytes.',
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            ));

            return ExitCode::Error->value;
        }

        $dir = $input->getStringOption('dir', $this->projectRoot . '/dist');
        $manifestPath = $input->getStringOption('manifest', $this->projectRoot . '/signatures.json');

        $manifestJson = file_get_contents($manifestPath);

        if (!is_string($manifestJson)) {
            $output->error(sprintf('Cannot read manifest file: %s', $manifestPath));

            return ExitCode::Error->value;
        }

        $output->info('Verifying artifact signatures...');

        try {
            $serializer = new SignatureManifestSerializer();
            $manifests = $serializer->deserialize($manifestJson);

            $service = new ReleaseSignerService(new ArtifactSigner());
            $result = $service->verifyDirectory($dir, $manifests, $publicKey);

            foreach ($result['valid'] as $path) {
                $output->success(sprintf('  VALID: %s', $path));
            }

            foreach ($result['invalid'] as $path) {
                $output->error(sprintf('  INVALID: %s', $path));
            }

            foreach ($result['missing'] as $path) {
                $output->warning(sprintf('  MISSING: %s', $path));
            }

            $totalValid = count($result['valid']);
            $totalInvalid = count($result['invalid']);
            $totalMissing = count($result['missing']);

            $output->writeln(sprintf(
                'Results: %d valid, %d invalid, %d missing',
                $totalValid,
                $totalInvalid,
                $totalMissing,
            ));

            if ($totalInvalid > 0 || $totalMissing > 0) {
                $output->error('Verification FAILED.');

                return ExitCode::Error->value;
            }

            $output->success('All signatures verified successfully.');

            return ExitCode::Success->value;
        } catch (RuntimeException $e) {
            $output->error($e->getMessage());

            return ExitCode::Error->value;
        }
    }
}
