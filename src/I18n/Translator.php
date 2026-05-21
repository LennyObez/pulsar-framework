<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Pulsar\Api\Internal;
use Pulsar\Config\I18nConfig;
use Pulsar\I18n\Exception\I18nException;
use Pulsar\I18n\Exception\MissingTranslationException;
use Pulsar\I18n\Format\MessageFormatterInterface;

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

        // Support dot-notation: "domain.key" splits to domain + key when the
        // caller uses the default domain. This allows @t('messages.skip_to_content')
        // to resolve as domain=messages, key=skip_to_content — matching the file-based
        // catalog structure (resources/lang/{locale}/messages.php → ['skip_to_content']).
        $resolvedKey = $key;
        $resolvedDomain = $domain;

        $dotPos = ($domain === 'messages') ? strpos($key, '.') : false;

        if ($dotPos !== false) {
            $candidateDomain = substr($key, 0, $dotPos);
            $candidateKey = substr($key, $dotPos + 1);

            // Only split if the candidate domain segment looks like a file name
            // (all lowercase, no spaces) to avoid splitting actual keys like "error.404"
            if ($candidateKey !== '' && preg_match('/^[a-z][a-z0-9_-]*$/', $candidateDomain) === 1) {
                $resolvedDomain = $candidateDomain;
                $resolvedKey = $candidateKey;
            }
        }

        // Search through the locale fallback chain
        foreach ($localeChain as $candidateLocale) {
            $entry = $this->catalog->get($resolvedKey, $candidateLocale, $resolvedDomain);

            if ($entry !== null) {
                return $this->formatMessage($entry->message, $parameters, $candidateLocale);
            }
        }

        // If dot-notation split didn't find a match, try the original key as-is
        // in case the key literally contains dots (e.g., "config.app.name")
        if ($resolvedKey !== $key) {
            foreach ($localeChain as $candidateLocale) {
                $entry = $this->catalog->get($key, $candidateLocale, $domain);

                if ($entry !== null) {
                    return $this->formatMessage($entry->message, $parameters, $candidateLocale);
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
            /** @var mixed $value */
            foreach ($parameters as $key => $value) {
                if (is_scalar($value)) {
                    $message = str_replace(':' . $key, (string) $value, $message);
                }
            }

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
