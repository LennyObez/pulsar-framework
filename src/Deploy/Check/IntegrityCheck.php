<?php

declare(strict_types=1);

namespace Pulsar\Deploy\Check;

use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Integrity\Exception\IntegrityException;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Integrity\ManifestVerifierInterface;
use SodiumException;

use function file_get_contents;
use function is_file;
use function sprintf;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the signed integrity manifest against the files on disk.
 *
 * Integrity checking detects unauthorized modifications to source files,
 * configuration, and binaries: a critical control for regulated environments.
 * The check reads the manifest named by `config/integrity.php`, verifies its
 * signature, and re-hashes every entry. A manifest that is absent, corrupt,
 * unsigned or invalidly signed fails the gate, as does any modified, missing
 * or added file — the configuration flag alone proves nothing.
 */
#[Internal]
final readonly class IntegrityCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'integrity';

    /**
     * @param ManifestVerifierInterface|null $verifier Null when integrity is
     *        disabled and the composition root built no verifier; a null
     *        verifier with integrity enabled is itself a failure.
     * @param ManifestSignerInterface|null $signer Null when no master key is
     *        available to derive the manifest signing subkey from.
     */
    public function __construct(
        private IntegrityConfig $integrityConfig,
        private ?ManifestVerifierInterface $verifier,
        private ?ManifestSignerInterface $signer,
        private string $basePath,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Verifies the signed integrity manifest against the files on disk';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if (!$this->integrityConfig->enabled) {
            return match ($environment) {
                // Production fails closed on missing integrity
                // verification. Tampered framework files in a regulated
                // deployment must trip the deploy gate, not a soft warning that
                // operators routinely ignore in the noise of a release pipeline.
                'production' => CheckResult::error(
                    self::CHECK_NAME,
                    'File integrity verification is disabled in production',
                    [
                        'Enable integrity verification in config/integrity.php — tampered framework files',
                        'must trip the deploy gate in regulated environments (PCI Req 11, HIPAA',
                        '§164.312(c)(1), ISO 27001 A.8.13).',
                        'Run "php bin/pulsar optimize" to generate the integrity manifest before deployment.',
                    ],
                ),
                'staging' => CheckResult::warning(
                    self::CHECK_NAME,
                    'File integrity verification is disabled',
                    [
                        'Enable integrity in staging to validate the manifest workflow before production.',
                    ],
                ),
                default => CheckResult::pass(
                    self::CHECK_NAME,
                    'Integrity verification not required in local environment',
                ),
            };
        }

        $failure = $this->verifyManifest();

        if ($failure === null) {
            return CheckResult::pass(
                self::CHECK_NAME,
                sprintf(
                    'Integrity manifest verified against the filesystem (mode: %s)',
                    $this->integrityConfig->mode->value,
                ),
            );
        }

        return match ($environment) {
            'production' => CheckResult::error(self::CHECK_NAME, $failure['message'], $failure['recommendations']),
            default => CheckResult::warning(self::CHECK_NAME, $failure['message'], $failure['recommendations']),
        };
    }

    /**
     * Verify the manifest, returning null on success or the failure to report.
     *
     * @return array{message: string, recommendations: list<string>}|null
     */
    private function verifyManifest(): ?array
    {
        if ($this->verifier === null) {
            return [
                'message' => 'Integrity verification is enabled but no manifest verifier is available',
                'recommendations' => [
                    'The integrity services failed to wire. Check that config/integrity.php loads',
                    'and that the application boots through the framework composition root.',
                ],
            ];
        }

        $manifestPath = $this->basePath
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $this->integrityConfig->manifestPath);

        if (!is_file($manifestPath)) {
            return [
                'message' => sprintf('Integrity manifest not found at "%s"', $manifestPath),
                'recommendations' => [
                    'Run "php bin/pulsar integrity:build" to generate and sign the manifest,',
                    'and ship it with the release artifact.',
                ],
            ];
        }

        $json = file_get_contents($manifestPath);

        if ($json === false) {
            return [
                'message' => sprintf('Integrity manifest at "%s" cannot be read', $manifestPath),
                'recommendations' => ['Check the file permissions on the integrity manifest.'],
            ];
        }

        try {
            $manifest = ManifestFormat::fromJson($json);
        } catch (IntegrityException $e) {
            return [
                'message' => sprintf('Integrity manifest is corrupted: %s', $e->getMessage()),
                'recommendations' => ['Regenerate the manifest with "php bin/pulsar integrity:build".'],
            ];
        }

        // The signature is checked before the hashes: an attacker who can
        // rewrite files can also rewrite an unsigned manifest to match them,
        // in which case every entry verifies and the check proves nothing.
        if ($manifest->signature === null) {
            return [
                'message' => 'Integrity manifest is unsigned',
                'recommendations' => [
                    'Generate the manifest on a host that has PULSAR_MASTER_KEY set so it is signed;',
                    'an unsigned manifest can be regenerated by anyone who can modify the files it covers.',
                ],
            ];
        }

        if ($this->signer === null) {
            return [
                'message' => 'Integrity manifest signature cannot be verified: no signing key is available',
                'recommendations' => [
                    'Set PULSAR_MASTER_KEY in the deployment environment so the manifest signing',
                    'subkey can be derived and the signature checked.',
                ],
            ];
        }

        try {
            $signatureValid = $this->signer->verify($manifest);
        } catch (SodiumException | JsonException $e) {
            return [
                'message' => sprintf('Integrity manifest signature check failed: %s', $e->getMessage()),
                'recommendations' => ['Regenerate the manifest with "php bin/pulsar integrity:build".'],
            ];
        }

        if (!$signatureValid) {
            return [
                'message' => 'Integrity manifest signature is invalid',
                'recommendations' => [
                    'The manifest was altered after signing, or it was signed with a different master key.',
                    'Rebuild the release and regenerate the manifest.',
                ],
            ];
        }

        try {
            $result = $this->verifier->verify($manifest);
        } catch (IntegrityException $e) {
            return [
                'message' => sprintf('Integrity manifest cannot be verified: %s', $e->getMessage()),
                'recommendations' => ['Regenerate the manifest with "php bin/pulsar integrity:build".'],
            ];
        }

        if ($result->passed) {
            return null;
        }

        return [
            'message' => sprintf(
                'Integrity verification failed: %d modified, %d missing, %d added',
                $result->modified,
                $result->missing,
                $result->added,
            ),
            'recommendations' => [
                'Run "php bin/pulsar integrity:verify" to list the offending paths.',
                'Redeploy from a clean artifact; do not regenerate the manifest to make the check pass.',
            ],
        ];
    }
}
