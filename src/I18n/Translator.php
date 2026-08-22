<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Exception\MissingTranslationException;
use Pulsar\I18n\Format\MessageFormatterInterface;

use function array_key_exists;
use function count;
use function in_array;
use function is_scalar;

/**
 * Main translator implementation.
 *
 * Resolves translations through the locale fallback chain and
 * formats messages using ICU MessageFormat or simple placeholders.
 */
#[Internal]
final class Translator implements TranslatorInterface
{
    private const int MAX_CHAIN_CACHE_SIZE = 200;

    public string $locale;

    /** @var array<string, list<string>> */
    private array $fallbackChainCache = [];

    private static ?self $globalInstance = null;

    public function __construct(
        private readonly CatalogInterface $catalog,
        private readonly I18nConfig $config,
        private readonly ?MessageFormatterInterface $formatter = null,
    ) {
        $this->locale = $config->defaultLocale;
    }

    public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'messages'): string
    {
        $targetLocale = $locale ?? $this->locale;
        $localeChain = $this->buildFallbackChain($targetLocale);

        // Resolve the literal key in the requested domain FIRST, so a key that
        // contains dots ("error.404", "app.name") resolves to its own entry and
        // is not split into a sibling domain that merely shares the name — the
        // safety the previous comment claimed but the split-first code lacked
        // (the /^[a-z]…$/ guard matches "error", so "error.404" was split).
        foreach ($localeChain as $candidateLocale) {
            $entry = $this->catalog->get($key, $candidateLocale, $domain);

            if ($entry !== null) {
                return $this->formatMessage($entry->message, $parameters, $candidateLocale);
            }
        }

        // Dot-notation fallback (default domain only): "domain.key" → domain +
        // key, attempted only when nothing matched the literal key. This still
        // lets @t('messages.skip_to_content') resolve as domain=messages,
        // key=skip_to_content without shadowing a literal dotted key.
        if ($domain === 'messages') {
            $dotPos = strpos($key, '.');

            if ($dotPos !== false) {
                $candidateDomain = substr($key, 0, $dotPos);
                $candidateKey = substr($key, $dotPos + 1);

                if ($candidateKey !== '' && preg_match('/^[a-z][a-z0-9_-]*$/', $candidateDomain) === 1) {
                    foreach ($localeChain as $candidateLocale) {
                        $entry = $this->catalog->get($candidateKey, $candidateLocale, $candidateDomain);

                        if ($entry !== null) {
                            return $this->formatMessage($entry->message, $parameters, $candidateLocale);
                        }
                    }
                }
            }
        }

        // Not found
        if ($this->config->strictMode) {
            throw MissingTranslationException::forKey($key, $targetLocale, $domain);
        }

        return $this->formatMessage($key, $parameters, $targetLocale);
    }

    public function has(string $key, ?string $locale = null, string $domain = 'messages'): bool
    {
        $targetLocale = $locale ?? $this->locale;
        $localeChain = $this->buildFallbackChain($targetLocale);

        return array_any($localeChain, fn(string $candidateLocale): bool => $this->catalog->has($key, $candidateLocale, $domain));
    }

    /**
     * Set the global translator instance (called by I18nWiring).
     */
    public static function setGlobalInstance(self $instance): void
    {
        self::$globalInstance = $instance;
    }

    /**
     * Get the global translator instance.
     *
     * @throws I18nException If not booted
     */
    public static function getGlobalInstance(): self
    {
        return self::$globalInstance ?? throw I18nException::notBooted();
    }

    /**
     * Reset the global instance (for testing).
     */
    public static function resetGlobalInstance(): void
    {
        self::$globalInstance = null;
    }

    /**
     * Build and cache the full fallback chain for a locale.
     *
     * @return list<string>
     */
    private function buildFallbackChain(string $targetLocale): array
    {
        if (isset($this->fallbackChainCache[$targetLocale])) {
            return $this->fallbackChainCache[$targetLocale];
        }

        $localeChain = Locale::parse($targetLocale)->fallbackChain();

        foreach ($this->config->fallbackLocales as $fallback) {
            if (!in_array($fallback, $localeChain, true)) {
                $localeChain[] = $fallback;
            }
        }

        // Evict oldest entries when cache is full
        if (count($this->fallbackChainCache) >= self::MAX_CHAIN_CACHE_SIZE) {
            array_shift($this->fallbackChainCache);
        }

        $this->fallbackChainCache[$targetLocale] = $localeChain;

        return $localeChain;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function formatMessage(string $message, array $parameters, string $locale): string
    {
        if ($parameters === []) {
            return $message;
        }

        // Support colon-prefixed :param placeholders alongside ICU {param} syntax.
        // Replace :key with the parameter value before passing to the ICU formatter.
        // This allows translation strings like "© :year Author" to work with
        // @t('messages.copyright', ['year' => 2026]).
        if (str_contains($message, ':')) {
            // Match the WHOLE :identifier token in one pass so a shorter key
            // cannot clobber a longer one sharing its prefix (e.g. :id inside
            // :identifier). Unknown or non-scalar placeholders are left intact.
            $message = (string) preg_replace_callback(
                '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
                static function (array $matches) use ($parameters): string {
                    $name = $matches[1];

                    if (array_key_exists($name, $parameters) && is_scalar($parameters[$name])) {
                        return (string) $parameters[$name];
                    }

                    return $matches[0];
                },
                $message,
            );

            // If all placeholders were resolved, skip the ICU formatter
            if (!str_contains($message, '{')) {
                return $message;
            }
        }

        if ($this->formatter === null) {
            return $message;
        }

        return $this->formatter->format($message, $parameters, $locale);
    }
}
