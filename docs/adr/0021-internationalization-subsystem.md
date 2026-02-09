# ADR-0021: Internationalization Subsystem

## Status

Accepted

## Context

Mission-critical applications in regulated domains - banking, healthcare, legal - operate across jurisdictions and languages. They require locale-aware message translation with ICU-compliant formatting (plurals, gender, number/currency/date formatting), locale negotiation from HTTP request data, fallback locale chains for graceful degradation, and CI-integrated linting to catch missing translations before deployment.

The i18n subsystem must work without the `ext-intl` PHP extension (not available in all deployment environments) while providing full ICU message formatting when `ext-intl` is present.

## Decision drivers

1. **Compliance**: Regulated domains require that user-facing messages display in the user's locale. Missing translations in production can constitute a compliance violation.
2. **Graceful degradation**: The `ext-intl` extension provides full ICU support but is optional. The subsystem must function without it using a regex-based fallback formatter.
3. **CI validation**: Missing translations must be caught at build time, not discovered in production. A linting tool must integrate with CI pipelines.
4. **Catalog flexibility**: Translation sources vary - PHP arrays for simple projects, JSON files for external translation services, composite chains for module-contributed translations.
5. **HTTP integration**: Locale selection must consider query parameters, route attributes, and Accept-Language headers per RFC 7231.

## Decision

Implement `src/I18n/` as a core internationalization module with the following architecture:

### Translator

`Translator` is the central translation facade. It resolves translations through a locale fallback chain:

1. Parse the target locale into a fallback chain (e.g., `fr_CA` → `fr_CA`, `fr`)
2. Append configured fallback locales (e.g., `en`)
3. Search the catalog chain for the first matching translation
4. Format the message with ICU parameters (if formatter is available)
5. In strict mode, throw `MissingTranslationException` if no translation is found; otherwise return the key as-is

```php
$translator->translate('order.confirmation', [
    'amount' => 1500.00,
    'currency' => 'EUR',
], locale: 'fr_CA', domain: 'messages');
```

A global instance (`Translator::getGlobalInstance()`) is set during kernel boot for use in helper functions, keeping the convenience of `__()` without introducing a service locator.

### Catalog backends

All catalogs implement `CatalogInterface` with `get()`, `has()`, and `allKeys()` methods:

| Catalog        | Source                               | Use Case                                             |
| -------------- | ------------------------------------ | ---------------------------------------------------- |
| `PhpCatalog`   | PHP arrays (`lang/en/messages.php`)  | Simple projects, IDE autocompletion                  |
| `JsonCatalog`  | JSON files (`lang/en/messages.json`) | External translation services, crowdsource platforms |
| `ChainCatalog` | Composite of other catalogs          | Module-contributed translations, override chains     |

`ChainCatalog` searches catalogs in priority order, enabling modules to provide default translations that applications can override.

### Message formatting

Two formatter implementations behind `MessageFormatterInterface`:

- `IcuMessageFormatter` - wraps `ext-intl` `MessageFormatter` for full ICU syntax (plurals, select, number/date/currency patterns)
- `FallbackMessageFormatter` - regex-based `{placeholder}` replacement when `ext-intl` is unavailable

Specialized formatters for currency (`IntlCurrencyFormatter`), dates (`IntlDateFormatter`), and numbers (`IntlNumberFormatter`) provide locale-aware formatting for common regulated-domain data types.

### Locale negotiation

`LocaleNegotiator` resolves the request locale from multiple sources in priority order:

1. Query parameter (`?locale=xx`) - explicit user selection
2. Request attribute (`_locale`) - route parameter from URL pattern
3. Accept-Language header - parsed per RFC 7231 with quality-sorted matching
4. Configuration default - fallback when no signal is available

The negotiator caps Accept-Language parsing at 20 tokens to prevent DoS via pathologically long headers. Language-prefix matching supports both directions: `fr_CA` request matches `fr` supported locale, and `fr` request matches `fr_CA` supported locale.

`LocaleMiddleware` integrates the negotiator into the HTTP pipeline, setting the translator locale before the request reaches the controller.

### Translation linting

`TranslationLinter` validates translation completeness across all configured locales and domains:

- Detects missing keys (present in reference locale, absent in target)
- Detects extra keys (present in target, absent in reference - possibly stale)
- Reports results as `LintResult` with `LintSeverity` levels

The linter integrates with the console command system for CI pipeline execution: `pulsar i18n:lint` returns a non-zero exit code on errors.

### Translation extraction

`TranslationExtractor` scans source files for translation key usage, producing `ExtractionResult` reports. This enables automated detection of keys used in code but not present in any catalog.

### Configuration

`I18nConfig` follows the readonly DTO pattern (ADR-0011) with properties: `defaultLocale`, `fallbackLocales`, `supportedLocales`, `strictMode`, `catalogPaths`, and formatter preferences.

### URL-Prefix Locale Routing (Addendum)

When `url_strategy` is set to `path_prefix`, a `LocalePrefixMiddleware` runs early in the HTTP pipeline. It extracts the locale from the first path segment (e.g., `/fr/about` → `fr`), strips the prefix, and sets `_locale`/`_locale_prefix` request attributes. This allows routes to be registered without locale prefixes — a single route definition serves all locales.

Key components:

| Component                    | Responsibility                                                           |
| ---------------------------- | ------------------------------------------------------------------------ |
| `UrlPrefixExtractor`         | Extract, strip, and build locale path prefixes with security protections |
| `LocalePrefixMiddleware`     | HTTP middleware — prefix detection, stripping, canonical redirect        |
| `LocaleUrlGenerator`         | Build locale-prefixed URLs, alternate URLs, and hreflang link sets       |
| `HreflangLink`               | Value object for `<link rel="alternate" hreflang="...">` tags            |
| `LocaleUrlResolverInterface` | Extension point for translated slug resolution (e.g., CMS content)       |
| `TemplateLocaleHelper`       | Template-facing helper with `current()`, `isRtl()`, `switchUrls()`       |

**Canonical redirects**: When `canonical_redirect` is enabled (default), GET/HEAD requests to the default locale's prefix (e.g., `/en/about`) receive a 301 redirect to the unprefixed URL (`/about`), preserving the query string. This prevents duplicate content in search engine indexes.

**Security**: The extractor validates locale segments (2–5 alphabetic characters), rejects path traversal patterns (`/../`, trailing `/..`, leading `../`), and collapses double slashes after prefix stripping to prevent protocol-relative open redirects.

## Alternatives considered

### Third-party translation library

Rejected: external libraries provide translation but not the CI linting, locale negotiation middleware, or fallback formatter integration that a framework must provide. The integration glue code would approach the size of the implementation itself.

### gettext

Rejected: gettext requires `.po`/`.mo` file compilation, process-global locale state (`setlocale()`), and does not support ICU message syntax (plurals, gender, number formatting). Process-global state is incompatible with persistent worker mode (ADR-0010) where multiple requests share a process.

### ICU-only without abstraction (require ext-intl)

Rejected: `ext-intl` is not available in all deployment environments (minimal Docker images, shared hosting, CI runners). The fallback formatter ensures basic functionality everywhere while ICU provides full formatting when available.

## Consequences

### Positive

- Full ICU message formatting when `ext-intl` is available; graceful degradation when it is not
- CI linting catches missing translations before production deployment
- Locale negotiation follows HTTP standards (RFC 7231) with multiple signal sources
- Catalog chain enables module-contributed translations with application-level overrides
- Strict mode option catches missing translations early in development

### Negative

- `FallbackMessageFormatter` supports only simple `{placeholder}` substitution - no ICU plurals, select, or number formatting without `ext-intl`
- Global translator instance (`Translator::getGlobalInstance()`) introduces controlled global state - acceptable for helper function convenience but must be reset between tests
- Three catalog backends and two formatters add test matrix surface

### Neutral

- `Locale::parse()` produces a value object with `fallbackChain()` - the chain is computed on every `translate()` call (no caching), which is acceptable given typical call volumes
- Translation extraction is source-file scanning (regex-based) - it may miss dynamically constructed keys, which is documented as a known limitation

## Security impact

- `LocaleNegotiator` caps Accept-Language parsing at 20 tokens - prevents request header DoS
- Locale values are validated against the configured `supportedLocales` list - arbitrary locale injection is not possible
- Translation keys are not evaluated as code - no template injection risk
- `MissingTranslationException` in strict mode prevents accidental information disclosure through raw translation keys in production

## Performance impact

- `Translator::translate()` performs a linear search through the fallback chain (typically 2-3 locales) and one catalog lookup per locale. Sub-millisecond for typical usage.
- `PhpCatalog` loads translation arrays on first access and caches them in memory. `JsonCatalog` deserializes JSON files on first access.
- `IcuMessageFormatter` compiles ICU patterns on each call. For hot-path messages, applications should cache formatted output.
- Locale negotiation adds one middleware layer per request - negligible compared to I/O-bound operations.
- `LocalePrefixMiddleware` adds one regex-free string comparison per path segment - effectively zero overhead. Canonical redirect short-circuits before the router is reached.

## Migration / rollback plan

Additive change - introduces `src/I18n/` as a new core module. To roll back: remove the module and replace `translate()` calls with raw strings. Translation files are application-owned and unaffected by framework rollback.

## Links

- ADR-0002: Module boundaries and `#[Internal]` namespace convention
- ADR-0010: Persistent worker runtime (locale reset between requests)
- ADR-0011: Typed readonly configuration DTOs (`I18nConfig`)
- ADR-0014: Kernel service wiring decomposition (I18nWiring)
