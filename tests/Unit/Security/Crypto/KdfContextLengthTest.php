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
use function glob;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function trim;

use const DIRECTORY_SEPARATOR;
use const GLOB_ONLYDIR;
use const SODIUM_CRYPTO_KDF_CONTEXTBYTES;

/**
 * `sodium_crypto_kdf_derive_from_key()` takes a context of exactly
 * SODIUM_CRYPTO_KDF_CONTEXTBYTES bytes, and {@see \Pulsar\Security\Crypto\MasterKey}
 * throws on any other length. A call site that passes a spelled-out label instead of
 * an 8-byte one therefore does not misbehave subtly — it takes down whatever
 * constructs it, at boot, in production.
 *
 * The PSD2 SCA dynamic-linking service shipped that way: its provider passed a
 * 24-byte context, and nothing noticed because no test ever resolved the binding.
 * Construction tests close that for the services that have them; this rule closes it
 * for every call site whose context is written down, including the ones no test
 * constructs yet.
 *
 * Contexts assembled at runtime (hashed labels, config values) are outside what a
 * static rule can judge and are left to `MasterKey`'s own check.
 *
 * Run in isolation:
 *   vendor/bin/phpunit -c tools/php/phpunit.xml --filter KdfContextLengthTest
 */
#[CoversNothing]
final class KdfContextLengthTest extends TestCase
{
    /**
     * Methods whose second argument is a KDF context.
     *
     * @var list<string>
     */
    private const array KDF_METHODS = [
        'deriveSubKey',
        'deriveSubKeyHex',
        'derivePreviousSubKey',
        'keyId',
        'previousKeyId',
    ];

    #[Test]
    public function every_written_down_kdf_context_is_exactly_eight_bytes(): void
    {
        $root = dirname(__DIR__, 4);

        $directories = [$root . DIRECTORY_SEPARATOR . 'src'];
        $extensionDirs = glob(
            $root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . '*'
            . DIRECTORY_SEPARATOR . 'src',
            GLOB_ONLYDIR,
        );

        if ($extensionDirs !== false) {
            foreach ($extensionDirs as $extensionDir) {
                $directories[] = $extensionDir;
            }
        }

        $violations = [];
        $checked = 0;

        foreach ($directories as $directory) {
            /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if ($source === false) {
                    continue;
                }

                foreach (self::literalContexts($source) as [$line, $call, $context]) {
                    $checked++;

                    if (strlen($context) === SODIUM_CRYPTO_KDF_CONTEXTBYTES) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d %s() context "%s" is %d bytes',
                        str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                        $line,
                        $call,
                        $context,
                        strlen($context),
                    );
                }
            }
        }

        // A rule that silently stops finding call sites stops being a rule. The
        // framework has had double-digit KDF call sites with literal contexts since
        // 1.0.0; a count near zero means the scan broke, not that the code changed.
        self::assertGreaterThan(
            5,
            $checked,
            'The KDF context scan found almost no call sites, which means it is no longer scanning.',
        );

        self::assertSame(
            [],
            $violations,
            sprintf(
                "KDF contexts must be exactly %d bytes (sodium_crypto_kdf_derive_from_key):\n%s",
                SODIUM_CRYPTO_KDF_CONTEXTBYTES,
                implode("\n", $violations),
            ),
        );
    }

    /**
     * The rule is only worth having if it reports a bad context, so it is shown the
     * exact shape the PSD2 provider shipped.
     */
    #[Test]
    public function fixture_detects_a_context_that_is_not_eight_bytes(): void
    {
        $offending = <<<'PHP'
            <?php
            $secretKey = $masterKey->deriveSubKey(
                SubKeyId::Psd2ScaDynamicLinking->value,
                'psd2-sca-dynamic-linking',
            );
            $fine = $masterKey->deriveSubKey(1, 'encrypt_');
            $runtime = $masterKey->deriveSubKey(2, $computedContext);
            PHP;

        $found = self::literalContexts($offending);

        self::assertCount(2, $found, 'Only written-down contexts are judged.');
        self::assertSame('psd2-sca-dynamic-linking', $found[0][2]);
        self::assertSame(24, strlen($found[0][2]));
        self::assertSame('encrypt_', $found[1][2]);
    }

    /**
     * Every KDF call in $source whose context argument is a single-quoted literal.
     *
     * @return list<array{int, string, string}> line, method name, context value
     */
    private static function literalContexts(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id !== T_STRING || !in_array($token->text, self::KDF_METHODS, true)) {
                continue;
            }

            $open = $i + 1;
            while ($open < $count && $tokens[$open]->isIgnorable()) {
                $open++;
            }

            if ($open >= $count || $tokens[$open]->text !== '(') {
                continue;
            }

            $arguments = self::arguments($tokens, $open, $count);

            if (count($arguments) < 2) {
                continue;
            }

            // A declaration, not a call: `function deriveSubKey(int $id, string $context)`.
            if (preg_match('/^(int|string|\?|\\\\)/', $arguments[0]) === 1) {
                continue;
            }

            if (preg_match("/^'([^'\\\\]*)'$/", $arguments[1], $matches) !== 1) {
                continue;
            }

            $found[] = [$token->line, $token->text, $matches[1]];
        }

        return $found;
    }

    /**
     * Split the argument list that starts at $open into top-level argument sources.
     *
     * @param array<PhpToken> $tokens as `PhpToken::tokenize()` returns them
     * @return list<string>
     */
    private static function arguments(array $tokens, int $open, int $count): array
    {
        $depth = 0;
        $arguments = [];
        $current = '';

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '(' || $text === '[') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    $arguments[] = trim($current);

                    break;
                }
            }

            if ($depth === 1 && $text === ',') {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $text;
        }

        return $arguments;
    }
}
