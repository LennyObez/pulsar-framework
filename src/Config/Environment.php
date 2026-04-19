<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_filter;
use function array_key_exists;
use function in_array;
use function is_file;
use function is_readable;
use function preg_replace;
use function rtrim;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;

/**
 * Loads environment variables from the OS and an optional `.env` file.
 *
 * OS environment variables always take precedence over `.env` file values.
 * The `.env` parser supports `KEY=VALUE` lines, `#` comments, and blank lines.
 * No interpolation is performed.
 */
#[Api(since: '1.0.0')]
final class Environment
{
    /**
     * Merged environment variables (OS + file, OS wins).
     *
     * @var array<string, string>
     */
    private array $variables;

    /**
     * @param array<string, string> $variables
     */
    private function __construct(array $variables)
    {
        $this->variables = $variables;
    }

    /**
     * F4.9: prefix allowlist for OS-level environment variables when an
     * operator hardens the loader via {@see loadFiltered()}. The list is
     * deliberately conservative — it covers Pulsar's own surface plus
     * common application namespaces and well-known shell-environment
     * basics — so a bare `getenv()` cannot leak unrelated process-level
     * variables (Apache `SetEnv`, php-fpm `env[]`, sibling-app secrets)
     * into the framework's view of the world.
     *
     * Operators with bespoke prefixes can extend or override this list
     * by passing an explicit allowlist to {@see loadFiltered()}.
     *
     * @var list<string>
     */
    public const array DEFAULT_PREFIX_ALLOWLIST = [
        'APP_',
        'PULSAR_',
        'DB_',
        'DATABASE_',
        'LOG_',
        'SESSION_',
        'MAIL_',
        'CACHE_',
        'QUEUE_',
        'REDIS_',
        'AWS_',
        'GCP_',
        'GOOGLE_',
        'AZURE_',
        'OAUTH_',
        'WEBAUTHN_',
        'STRIPE_',
        'PAYPAL_',
        'PSD2_',
        'EIDAS_',
        'GHCR_',
        'GITHUB_',
    ];

    /**
     * F4.9: explicit single-name allowlist. Some shell-environment basics
     * are useful (`HOME`, `PATH`, `TZ`, `LANG`) but do not match any of
     * the prefix patterns. The framework keeps them available so that
     * downstream code reading `Environment::get('PATH')` still works
     * after `loadFiltered()`.
     *
     * @var list<string>
     */
    public const array DEFAULT_LITERAL_ALLOWLIST = [
        'HOME',
        'PATH',
        'PWD',
        'TZ',
        'LANG',
        'LC_ALL',
        'TERM',
        'USER',
        'USERNAME',
        'TMPDIR',
        'TEMP',
        'TMP',
    ];

    /**
     * Create an Environment instance from OS env vars and an optional `.env` file.
     *
     * OS vars are read first. If an `.env` file path is provided and exists,
     * its values are loaded but never override existing OS vars.
     *
     * Note: this method is unfiltered for backwards compatibility — it
     * exposes every OS environment variable. Operators in regulated
     * deployments should switch to {@see loadFiltered()} which enforces
     * a prefix allowlist and prevents adjacent-process secrets from
     * leaking into Pulsar's environment view (F4.9).
     */
    #[NoDiscard]
    public static function load(?string $envFilePath = null): self
    {
        return self::doLoad($envFilePath, null, null);
    }

    /**
     * F4.9: load environment with a prefix-based allowlist applied to
     * the OS-level vars. The `.env` file values are not filtered (they
     * are already curated by the operator). The merged set still has OS
     * vars winning over file vars for any key that survives the filter.
     *
     * @param list<string>|null $prefixAllowlist  Defaults to {@see DEFAULT_PREFIX_ALLOWLIST}.
     * @param list<string>|null $literalAllowlist Defaults to {@see DEFAULT_LITERAL_ALLOWLIST}.
     */
    #[NoDiscard]
    public static function loadFiltered(
        ?string $envFilePath = null,
        ?array $prefixAllowlist = null,
        ?array $literalAllowlist = null,
    ): self {
        return self::doLoad(
            $envFilePath,
            $prefixAllowlist ?? self::DEFAULT_PREFIX_ALLOWLIST,
            $literalAllowlist ?? self::DEFAULT_LITERAL_ALLOWLIST,
        );
    }

    /**
     * @param list<string>|null $prefixAllowlist
     * @param list<string>|null $literalAllowlist
     */
    private static function doLoad(
        ?string $envFilePath,
        ?array $prefixAllowlist,
        ?array $literalAllowlist,
    ): self {
        $osVars = self::readOsVars();

        if ($prefixAllowlist !== null) {
            $osVars = self::applyAllowlist($osVars, $prefixAllowlist, $literalAllowlist ?? []);
        }

        $fileVars = [];

        if ($envFilePath !== null && is_file($envFilePath) && is_readable($envFilePath)) {
            $fileVars = self::parseEnvFile($envFilePath);
        }

        // OS vars take precedence: file vars fill in gaps only
        $merged = $fileVars;
        foreach ($osVars as $key => $value) {
            $merged[$key] = $value;
        }

        return new self($merged);
    }

    /**
     * @param array<string, string> $vars
     * @param list<string> $prefixAllowlist
     * @param list<string> $literalAllowlist
     *
     * @return array<string, string>
     */
    private static function applyAllowlist(array $vars, array $prefixAllowlist, array $literalAllowlist): array
    {
        return array_filter(
            $vars,
            static function (string $key) use ($prefixAllowlist, $literalAllowlist): bool {
                if (in_array($key, $literalAllowlist, true)) {
                    return true;
                }

                foreach ($prefixAllowlist as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        return true;
                    }
                }

                return false;
            },
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Get an environment variable value.
     */
    #[NoDiscard]
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->variables[$key] ?? $default;
    }

    /**
     * Get a required environment variable or throw.
     *
     * @throws ConfigException If the variable is not set.
     */
    public function require(string $key): string
    {
        if (!array_key_exists($key, $this->variables)) {
            throw ConfigException::missingRequired($key, 'environment');
        }

        return $this->variables[$key];
    }

    /**
     * Check if an environment variable is set.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->variables);
    }

    /**
     * Resolve the application environment mode.
     */
    public function resolveMode(): EnvironmentMode
    {
        $env = $this->get('APP_ENV');

        if ($env === null) {
            return EnvironmentMode::Local;
        }

        $mode = EnvironmentMode::tryFrom($env);

        return $mode ?? EnvironmentMode::Local;
    }

    /**
     * Get all environment variables.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->variables;
    }

    /**
     * Read OS-level environment variables.
     *
     * @return array<string, string>
     */
    private static function readOsVars(): array
    {
        /** @var array<string, string> $env */
        $env = getenv();

        return $env;
    }

    /**
     * Parse a `.env` file into key-value pairs.
     *
     * Supports:
     * - `KEY=VALUE`
     * - `export KEY=VALUE` (export prefix stripped)
     * - `#` comments (full-line and inline on unquoted values)
     * - Blank lines
     * - Quoted values (single and double quotes stripped from both ends)
     *
     * F4.8: deliberate non-features. The parser is intentionally
     * primitive — Pulsar treats `.env` as a developer-convenience
     * fallback and routes operational secrets through the OS
     * environment / KMS instead. Specifically:
     *
     *   - **No escape sequences**. `\"` inside a double-quoted value
     *     is left as-is; embed double-quotes by switching to single
     *     quotes (`'foo"bar'`).
     *   - **No multi-line values**. Each line is parsed independently;
     *     a line break inside quotes is not honoured. Use `\n` in
     *     single-line strings or move the value to OS env vars.
     *   - **No `${VAR}` interpolation**. Each value is taken
     *     literally — no expansion of other variables, no command
     *     substitution. Compose interpolated values in the calling
     *     environment before exporting them.
     *
     * Operators that need full POSIX-shell semantics should pull in
     * `vlucas/phpdotenv` and pass the resulting array through
     * `Environment::loadFiltered()` for the same allowlist guarantees.
     *
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $vars = [];
        $lines = explode("\n", $contents);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip blank lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip export prefix
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $equalsPos = strpos($line, '=');

            if ($equalsPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equalsPos));
            $value = trim(substr($line, $equalsPos + 1));

            // Strip inline comments for unquoted values
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                $value = rtrim($value);
            }

            // Strip surrounding quotes
            if (
                ($value !== '')
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
