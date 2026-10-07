<?php

declare(strict_types=1);

namespace Pulsar\I18n\Compiler;

use Pulsar\Api\Internal;
use Pulsar\I18n\CatalogInterface;

use function array_keys;
use function json_encode;

use const JSON_FORCE_OBJECT;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Compiles translation catalog entries to JSON for client-side consumption.
 *
 * Takes a catalog backend and produces a flat key-value JSON string
 * for a given locale and domain, suitable for serving via the i18n API.
 */
#[Internal]
final readonly class TranslationCompiler implements TranslationCompilerInterface
{
    public function __construct(
        private CatalogInterface $catalog,
    ) {}

    /**
     * Compile all entries for a locale and domain into a JSON string.
     *
     * @param string $locale Target locale code
     * @param string $domain Translation domain
     * @param bool $prettyPrint Whether to indent the JSON output
     */
    public function compile(string $locale, string $domain = 'messages', bool $prettyPrint = false): string
    {
        $entries = $this->catalog->all($locale, $domain);
        $output = [];

        foreach ($entries as $key => $entry) {
            $output[$key] = $entry->message;
        }

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT;

        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($output, $flags);
    }

    /**
     * Compile all domains for a locale into a single JSON string.
     *
     * @param string $locale Target locale code
     * @param list<string> $domains Domain names to include
     */
    public function compileAll(string $locale, array $domains): string
    {
        $output = [];

        foreach ($domains as $domain) {
            $entries = $this->catalog->all($locale, $domain);

            foreach ($entries as $key => $entry) {
                $domainKey = $domain === 'messages' ? $key : $domain . '.' . $key;
                $output[$domainKey] = $entry->message;
            }
        }

        return json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    }

    /**
     * Get the list of available keys for a locale and domain.
     *
     * @return list<string>
     */
    public function keys(string $locale, string $domain = 'messages'): array
    {
        return array_keys($this->catalog->all($locale, $domain));
    }
}
