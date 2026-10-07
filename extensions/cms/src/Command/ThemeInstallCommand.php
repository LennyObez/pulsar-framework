<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Command;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeManifest;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function bin2hex;
use function copy;
use function file_exists;
use function file_get_contents;
use function hash_file;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rtrim;
use function scandir;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Install a CMS theme from a local directory containing a theme.json manifest.
 *
 * Reads the manifest, copies the theme files into the configured themes
 * storage directory, and registers the theme in the database.
 *
 * Usage:
 *   pulsar cms:theme:install /path/to/my-theme
 *
 * @psalm-api Resolved by the console application from the DI
 *            container, registered under `cms:theme:install`.
 */
#[Internal]
final class ThemeInstallCommand extends Command
{
    public function __construct(
        private readonly ThemeRepositoryInterface $repository,
        private readonly string $themesStoragePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'cms:theme:install';
        $this->description = 'Install a CMS theme from a local directory';

        $this->addArgument('path', 'Path to the theme directory containing theme.json', required: true);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourcePath = $input->getArgument(0);

        if (!is_string($sourcePath) || $sourcePath === '') {
            $output->error('Missing required argument: path to theme directory');

            return ExitCode::Invalid->value;
        }

        $sourcePath = rtrim($sourcePath, '/\\');

        if (!is_dir($sourcePath)) {
            $output->error(sprintf('Not a directory: %s', $sourcePath));

            return ExitCode::Error->value;
        }

        $manifestPath = $sourcePath . '/theme.json';

        if (!file_exists($manifestPath)) {
            $output->error(sprintf('No theme.json found in: %s', $sourcePath));

            return ExitCode::Error->value;
        }

        $manifestContents = file_get_contents($manifestPath);

        if ($manifestContents === false || $manifestContents === '') {
            $output->error('Failed to read theme.json or file is empty');

            return ExitCode::Error->value;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($manifestContents, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            $output->error('theme.json must contain a JSON object');

            return ExitCode::Error->value;
        }

        /** @var array<string, mixed> $manifestData */
        $manifestData = $decoded;
        $manifest = ThemeManifest::fromArray($manifestData);

        if ($manifest->slug === '') {
            $output->error('theme.json is missing the required "slug" field');

            return ExitCode::Error->value;
        }

        // Check for existing theme with the same slug
        $existing = $this->repository->findBySlug($manifest->slug);

        if ($existing !== null) {
            $output->error(sprintf('A theme with slug "%s" is already installed', $manifest->slug));

            return ExitCode::Error->value;
        }

        // Copy theme files to storage
        $targetDir = rtrim($this->themesStoragePath, '/\\') . '/' . $manifest->slug;

        if (!is_dir($targetDir) && !mkdir($targetDir, 0o755, true)) {
            $output->error(sprintf('Failed to create theme directory: %s', $targetDir));

            return ExitCode::Error->value;
        }

        $this->copyDirectory($sourcePath, $targetDir);

        // Compute hashes
        $manifestHash = hash_file('sha256', $targetDir . '/theme.json');
        $packageHash = hash_file('sha256', $manifestPath);

        if ($manifestHash === false || $packageHash === false) {
            $output->error('Failed to compute file hashes');

            return ExitCode::Error->value;
        }

        $now = new DateTimeImmutable();
        $themeId = bin2hex(random_bytes(16));

        $theme = new InstalledTheme(
            id: $themeId,
            tenantId: null,
            slug: $manifest->slug,
            displayName: $manifest->displayName,
            version: $manifest->version,
            description: $manifest->description,
            authorName: $manifest->authorName,
            authorUrl: $manifest->authorUrl,
            license: $manifest->license,
            manifestHash: $manifestHash,
            packageHash: $packageHash,
            provenanceVerified: false,
            signatureVerified: false,
            isActive: false,
            storagePath: $targetDir,
            installedAt: $now,
            installedBy: 'cli',
            activatedAt: null,
            activatedBy: null,
            deactivatedAt: null,
            deletedAt: null,
        );

        $this->repository->save($theme);

        $output->success(sprintf(
            'Theme "%s" (v%s) installed with slug "%s"',
            $manifest->displayName,
            $manifest->version,
            $manifest->slug,
        ));

        return ExitCode::Success->value;
    }

    /**
     * Recursively copy a directory tree.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        $entries = scandir($source);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $source . '/' . $entry;
            $dstPath = $destination . '/' . $entry;

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0o755, true);
                }

                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
}
