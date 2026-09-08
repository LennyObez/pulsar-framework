<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function is_dir;
use function ksort;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * `sodium_crypto_kdf_derive_from_key()` is the one primitive in the framework that
 * takes the MASTER KEY itself as an argument. Everything else in the crypto stack
 * — the AEAD suites, the HMACs, the generic hashes — consumes a sub-key that was
 * already derived, so a mistake there costs one subsystem's key. A mistake here
 * costs the root.
 *
 * {@see \Pulsar\Security\Crypto\MasterKey} is the single place designed to hold
 * that root: it takes the raw bytes through a `#[SensitiveParameter]` constructor,
 * refuses serialization, redacts `__debugInfo()`, and zeroes both the current and
 * the previous key in `__destruct()`. A caller that reaches the primitive directly
 * has to obtain the raw key from somewhere and hold it in a variable of its own,
 * and none of those guarantees follow it there. It also steps around
 * {@see \Pulsar\Security\Crypto\KeyProviderInterface}, which is the seam a
 * deployment substitutes to move derivation into an HSM or a KMS — a direct call
 * cannot be redirected, so such a deployment silently keeps one subsystem on
 * process-local key material.
 *
 * `extensions/studio/src/Console/Evidence/HashChain.php` shipped exactly that:
 * `deriveSeedFromMasterKey(string $masterKeyRaw)` took the 32 raw bytes as a plain
 * string parameter and called the primitive itself. Its `(15, 'stu_seed')` pair was
 * unique, so {@see SubKeyIdRegistryTest} — which judges WHICH key is derived — had
 * nothing to say about it. This rule judges WHO may derive at all, which is the
 * half that was unenforced.
 *
 * Scope, stated so the next reader does not widen it by accident:
 *
 * - This rule covers the KDF primitive only. AEAD primitives
 *   (`sodium_crypto_aead_*`), `sodium_crypto_auth*` and `sodium_crypto_generichash`
 *   take a derived sub-key or no key at all, so calling them outside the crypto
 *   module is not a bypass of the master-key seam.
 *   `src/Queue/Middleware/AeadPayloadEncryptor.php` is the clearest case: it calls
 *   `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt()` directly, and the key it
 *   passes came from `MasterKey::deriveSubKey(10, 'que_aead')`. Nothing about that
 *   call touches the root key, and forbidding it would need an allowlist — which
 *   is the suppression this repository does not ship.
 * - Test suites are out of scope. A test of the KDF has to call the KDF.
 *
 * Run in isolation:
 *   vendor/bin/phpunit -c tools/php/phpunit.xml --filter KdfSeamTest
 */
#[CoversNothing]
final class KdfSeamTest extends TestCase
{
    /** The primitive that consumes master key material. */
    private const string KDF_PRIMITIVE = 'sodium_crypto_kdf_derive_from_key';

    /**
     * The only directory allowed to call the primitive: the crypto module, which
     * owns key loading, key zeroing and the provider seam.
     */
    private const string CRYPTO_MODULE = 'src/Security/Crypto/';

    #[Test]
    public function only_the_crypto_module_calls_the_key_derivation_primitive(): void
    {
        $callSites = self::callSites(self::shippedSources(dirname(__DIR__, 4)));

        // A rule that silently stops finding call sites stops being a rule.
        // MasterKey alone derives twice: the current key and the rotation key.
        self::assertGreaterThanOrEqual(
            2,
            count($callSites),
            'The KDF seam scan found fewer calls than MasterKey itself makes, '
            . 'which means it is no longer scanning.',
        );

        $violations = [];

        foreach ($callSites as $site) {
            if (str_starts_with($site['file'], self::CRYPTO_MODULE)) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d calls %s() directly. Master key material handled outside '
                . 'Pulsar\Security\Crypto\MasterKey is not zeroed on destruction and '
                . 'cannot be redirected through KeyProviderInterface. Take a '
                . 'KeyProviderInterface and call deriveSubKey()/deriveSubKeyHex() instead.',
                $site['file'],
                $site['line'],
                self::KDF_PRIMITIVE,
            );
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * The rule has to report the shape it was written for, or its silence on the
     * real tree says nothing. This is `HashChain::deriveSeedFromMasterKey()` as it
     * shipped: an extension holding the raw master key in a string parameter and
     * deriving from it itself.
     */
    #[Test]
    public function fixture_reports_a_direct_call_from_outside_the_crypto_module(): void
    {
        $bypass = <<<'PHP'
            <?php
            final class HashChain
            {
                public static function deriveSeedFromMasterKey(string $masterKeyRaw): string
                {
                    return bin2hex(sodium_crypto_kdf_derive_from_key(32, 15, 'stu_seed', $masterKeyRaw));
                }
            }
            PHP;

        $sites = self::callSites([
            'extensions/studio/src/Console/Evidence/HashChain.php' => $bypass,
        ]);

        self::assertCount(1, $sites);
        self::assertSame('extensions/studio/src/Console/Evidence/HashChain.php', $sites[0]['file']);
        self::assertSame(6, $sites[0]['line']);
    }

    /**
     * Naming the primitive is not calling it. An import, a docblock and a routed
     * derivation through the provider must all read as clean, or the rule fires on
     * the very files that fixed the problem it reports.
     */
    #[Test]
    public function fixture_ignores_mentions_that_are_not_calls(): void
    {
        $mentions = <<<'PHP'
            <?php
            use function sodium_crypto_kdf_derive_from_key;

            /**
             * Derived through sodium_crypto_kdf_derive_from_key() inside MasterKey.
             */
            final class Routed
            {
                public function seed(KeyProviderInterface $keys): string
                {
                    return $keys->deriveSubKeyHex(15, 'stu_seed', 32);
                }
            }
            PHP;

        self::assertSame([], self::callSites(['extensions/studio/src/Routed.php' => $mentions]));
    }

    /**
     * Every shipped PHP file under `src/` and `extensions/`, keyed by
     * repository-relative path with forward slashes.
     *
     * @return array<string, string>
     */
    private static function shippedSources(string $root): array
    {
        $sources = [];

        foreach (['src', 'extensions'] as $directory) {
            $base = $root . DIRECTORY_SEPARATOR . $directory;

            if (!is_dir($base)) {
                continue;
            }

            /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', $file->getPathname());
                $relative = str_replace(str_replace('\\', '/', $root) . '/', '', $relative);

                if (
                    str_contains($relative, '/tests/')
                    || str_contains($relative, '/vendor/')
                    || str_contains($relative, '/node_modules/')
                ) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if ($source === false) {
                    continue;
                }

                $sources[$relative] = $source;
            }
        }

        ksort($sources);

        return $sources;
    }

    /**
     * Every position at which the KDF primitive is invoked.
     *
     * A bare mention is not a call: `use function` imports carry no parenthesis and
     * docblock references are not tokenised as identifiers at all, so both fall out
     * without needing to be named.
     *
     * @param array<string, string> $sources path => PHP source
     *
     * @return list<array{file: string, line: int}>
     */
    private static function callSites(array $sources): array
    {
        $found = [];

        foreach ($sources as $path => $source) {
            if (!str_contains($source, self::KDF_PRIMITIVE)) {
                continue;
            }

            $tokens = PhpToken::tokenize($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]->id !== T_STRING || $tokens[$i]->text !== self::KDF_PRIMITIVE) {
                    continue;
                }

                $next = $i + 1;
                while ($next < $count && $tokens[$next]->isIgnorable()) {
                    $next++;
                }

                if ($next >= $count || $tokens[$next]->text !== '(') {
                    continue;
                }

                $found[] = ['file' => $path, 'line' => $tokens[$i]->line];
            }
        }

        return $found;
    }
}
