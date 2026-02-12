<?php

declare(strict_types=1);

namespace Pulsar\I18n\Catalog;

use Pulsar\Api\Internal;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\TranslationEntry;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_last_error;

use const JSON_ERROR_NONE;

/**
 * Catalog backend that loads JSON files.
 *
 * Expects files at `{catalogPath}/{locale}/{domain}.json`.
 */
#[Internal]
final class JsonCatalog implements CatalogInterface
{
    /** @var array<string, array<string, TranslationEntry>> Loaded entries keyed by "{locale}:{domain}" */
    private array $loaded = [];

    /** @var array<string, true> Negative cache for missing domain files */
    private array $missingDomains = [];

    public function __construct(
        private readonly string $catalogPath,
    ) {}

    public function get(string $key, string $locale, string $domain = 'messages'): ?TranslationEntry
    {
        $entries = $this->loadDomain($locale, $domain);

        return $entries[$key] ?? null;
    }

    public function has(string $key, string $locale, string $domain = 'messages'): bool
    {
        $entries = $this->loadDomain($locale, $domain);

        return array_key_exists($key, $entries);
    }

    /**
     * @return array<string, TranslationEntry>
     */
    public function all(string $locale, string $domain = 'messages'): array
    {
        return $this->loadDomain($locale, $domain);
    }

    /**
     * @return array<string, TranslationEntry>
     */
    private function loadDomain(string $locale, string $domain): array
    {
        $cacheKey = $locale . ':' . $domain;

        if (array_key_exists($cacheKey, $this->loaded)) {
            return $this->loaded[$cacheKey];
        }

        if (array_key_exists($cacheKey, $this->missingDomains)) {
            return [];
        }

        $path = $this->catalogPath . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $domain . '.json';

        if (!is_file($path)) {
            $this->missingDomains[$cacheKey] = true;
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->missingDomains[$cacheKey] = true;
            return [];
        }

        $raw = json_decode($contents, true);

        if (!is_array($raw) || json_last_error() !== JSON_ERROR_NONE) {
            $this->missingDomains[$cacheKey] = true;
            return [];
        }

        $entries = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $entries[$key] = self::buildEntry($key, $value);
        }

        $this->loaded[$cacheKey] = $entries;

        return $entries;
    }

    private static function buildEntry(string $key, mixed $value): TranslationEntry
    {
        if (is_string($value)) {
            return new TranslationEntry(key: $key, message: $value);
        }

        if (is_array($value) && isset($value['message']) && is_string($value['message'])) {
            return new TranslationEntry(
                key: $key,
                message: $value['message'],
                htmlSafe: isset($value['html_safe']) && $value['html_safe'] === true,
                context: isset($value['context']) && is_string($value['context']) ? $value['context'] : null,
                maxLength: isset($value['max_length']) && is_int($value['max_length']) ? $value['max_length'] : null,
            );
        }

        return new TranslationEntry(key: $key, message: $key);
    }
}
