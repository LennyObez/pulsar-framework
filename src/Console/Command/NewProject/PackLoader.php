<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\NewProject;

use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function file_get_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function scandir;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Loads and validates scaffolding pack manifests from the packs directory.
 *
 * Packs are stored under `resources/packs/{name}/pack.json`.
 */
#[Internal]
final readonly class PackLoader
{
    private string $packsDirectory;

    public function __construct(?string $packsDirectory = null)
    {
        $this->packsDirectory = $packsDirectory
            ?? dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'packs';
    }

    /**
     * Load a pack manifest by name.
     *
     * @throws RuntimeException If the pack does not exist or its manifest is invalid
     */
    #[NoDiscard]
    public function load(string $packName): PackManifest
    {
        $packDir = $this->packsDirectory . DIRECTORY_SEPARATOR . $packName;

        if (!is_dir($packDir)) {
            throw new RuntimeException(sprintf(
                'Control pack "%s" does not exist. Available packs: %s',
                $packName,
                implode(', ', $this->available()),
            ));
        }

        $manifestPath = $packDir . DIRECTORY_SEPARATOR . 'pack.json';

        if (!is_file($manifestPath)) {
            throw new RuntimeException(sprintf(
                'Control pack "%s" is missing its pack.json manifest.',
                $packName,
            ));
        }

        $content = file_get_contents($manifestPath);

        if ($content === false) {
            throw new RuntimeException(sprintf(
                'Failed to read pack manifest: %s',
                $manifestPath,
            ));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf(
                'Invalid JSON in pack manifest "%s": %s',
                $manifestPath,
                $e->getMessage(),
            ), 0, $e);
        }

        try {
            return PackManifest::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(sprintf(
                'Invalid pack manifest for "%s": %s',
                $packName,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * List all available pack names.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function available(): array
    {
        if (!is_dir($this->packsDirectory)) {
            return [];
        }

        $entries = scandir($this->packsDirectory);

        if ($entries === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn(string $entry): string => $entry,
                $entries,
            ),
            fn(string $entry): bool => $entry !== '.'
                && $entry !== '..'
                && is_dir($this->packsDirectory . DIRECTORY_SEPARATOR . $entry)
                && is_file($this->packsDirectory . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . 'pack.json'),
        ));
    }

    /**
     * Return the base directory for a named pack.
     */
    #[NoDiscard]
    public function packDirectory(string $packName): string
    {
        return $this->packsDirectory . DIRECTORY_SEPARATOR . $packName;
    }
}
