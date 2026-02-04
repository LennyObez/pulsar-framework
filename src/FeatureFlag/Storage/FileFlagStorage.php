<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag\Storage;

use function array_map;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

use JsonException;

use const LOCK_EX;

use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagStorageInterface;

use function sprintf;

/**
 * File-based feature flag storage using JSON.
 */
final class FileFlagStorage implements FlagStorageInterface
{
    /** @var array<string, FlagDefinition>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $filePath,
    ) {}

    public function get(string $name): ?FlagDefinition
    {
        $flags = $this->loadFlags();

        return $flags[$name] ?? null;
    }

    public function all(): array
    {
        return $this->loadFlags();
    }

    public function has(string $name): bool
    {
        $flags = $this->loadFlags();

        return isset($flags[$name]);
    }

    public function set(FlagDefinition $flag): void
    {
        $flags = $this->loadFlags();
        $flags[$flag->name] = $flag;
        $this->saveFlags($flags);
    }

    public function remove(string $name): void
    {
        $flags = $this->loadFlags();
        unset($flags[$name]);
        $this->saveFlags($flags);
    }

    /**
     * @return array<string, FlagDefinition>
     *
     * @throws FeatureFlagException
     */
    private function loadFlags(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!file_exists($this->filePath)) {
            $this->cache = [];

            return [];
        }

        $content = file_get_contents($this->filePath);

        if ($content === false) {
            throw FeatureFlagException::storageError(sprintf('Cannot read file: %s', $this->filePath));
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw FeatureFlagException::storageError(sprintf('Invalid JSON in %s: %s', $this->filePath, $e->getMessage()));
        }

        if (!is_array($data)) {
            throw FeatureFlagException::storageError(sprintf('Expected JSON object in %s', $this->filePath));
        }

        $flags = [];

        /** @var array<string, array<string, mixed>> $data */
        foreach ($data as $name => $flagData) {
            /** @var array<string, mixed> $flagData */
            $flags[$name] = FlagDefinition::fromArray($name, $flagData);
        }

        $this->cache = $flags;

        return $flags;
    }

    /**
     * @param array<string, FlagDefinition> $flags
     *
     * @throws FeatureFlagException
     * @throws JsonException
     */
    private function saveFlags(array $flags): void
    {
        $data = array_map(static fn(FlagDefinition $flag): array => $flag->toArray(), $flags);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $result = file_put_contents($this->filePath, $json, LOCK_EX);

        if ($result === false) {
            throw FeatureFlagException::storageError(sprintf('Cannot write file: %s', $this->filePath));
        }

        $this->cache = $flags;
    }
}
