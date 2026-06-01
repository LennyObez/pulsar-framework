<?php

declare(strict_types=1);

namespace Pulsar\I18n\Linter;

use MessageFormatter;
use Pulsar\Api\Api;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\CatalogInterface;

use function array_keys;
use function count;
use function extension_loaded;
use function in_array;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * Validates translation catalogs for common issues.
 *
 * Checks:
 * 1. HTML in non-safe entries (Warning)
 * 2. Invalid ICU patterns (Error, requires ext-intl)
 * 3. Missing translations across locales (Warning)
 * 4. Max-length violations (Warning)
 * 5. Orphaned translations not in extraction manifest (Warning)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TranslationLinter
{
    public function __construct(
        private CatalogInterface $catalog,
        private I18nConfig $config,
    ) {}

    /**
     * Lint all configured locales for the given domain.
     *
     * @param ?array<string, array<string, list<string>>> $extractedKeys Extracted manifest for orphan detection
     */
    public function lint(string $domain = 'messages', ?array $extractedKeys = null): LintResult
    {
        $issues = [];

        foreach ($this->config->supportedLocales as $locale) {
            $entries = $this->catalog->all($locale, $domain);

            foreach ($entries as $key => $entry) {
                // 1. HTML in non-safe entries
                if (!$entry->htmlSafe && preg_match('/<[a-z][^>]*>/i', $entry->message) === 1) {
                    $issues[] = [
                        'severity' => LintSeverity::Warning,
                        'key' => $key,
                        'locale' => $locale,
                        'domain' => $domain,
                        'message' => 'Contains HTML but html_safe is not set',
                    ];
                }

                // 2. Invalid ICU patterns
                if (extension_loaded('intl') && str_contains($entry->message, '{') && str_contains($entry->message, ',')) {
                    $formatter = MessageFormatter::create($locale, $entry->message);

                    if ($formatter === null) {
                        $issues[] = [
                            'severity' => LintSeverity::Error,
                            'key' => $key,
                            'locale' => $locale,
                            'domain' => $domain,
                            'message' => 'Invalid ICU MessageFormat pattern',
                        ];
                    }
                }

                // 4. Max-length violations
                if ($entry->maxLength !== null && mb_strlen($entry->message) > $entry->maxLength) {
                    $issues[] = [
                        'severity' => LintSeverity::Warning,
                        'key' => $key,
                        'locale' => $locale,
                        'domain' => $domain,
                        'message' => sprintf(
                            'Message length %d exceeds max_length %d',
                            mb_strlen($entry->message),
                            $entry->maxLength,
                        ),
                    ];
                }

                // 5. Orphaned translations
                if ($extractedKeys !== null && !isset($extractedKeys[$domain][$key])) {
                    $issues[] = [
                        'severity' => LintSeverity::Warning,
                        'key' => $key,
                        'locale' => $locale,
                        'domain' => $domain,
                        'message' => 'Translation key not found in extracted source manifest',
                    ];
                }
            }
        }

        // 3. Missing translations across locales
        if (count($this->config->supportedLocales) > 1) {
            $allKeys = [];

            foreach ($this->config->supportedLocales as $locale) {
                foreach (array_keys($this->catalog->all($locale, $domain)) as $key) {
                    $allKeys[$key][] = $locale;
                }
            }

            foreach ($allKeys as $key => $locales) {
                foreach ($this->config->supportedLocales as $locale) {
                    if (!in_array($locale, $locales, true)) {
                        $issues[] = [
                            'severity' => LintSeverity::Warning,
                            'key' => $key,
                            'locale' => $locale,
                            'domain' => $domain,
                            'message' => 'Translation missing in this locale',
                        ];
                    }
                }
            }
        }

        return new LintResult($issues);
    }
}
