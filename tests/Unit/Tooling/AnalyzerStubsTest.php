<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use JetBrains\PHPStormStub\PhpStormStubsMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Guards the two ways the analyser stub set has already gone wrong.
 *
 * 1. ORPHANED — eight of the fourteen stub files on disk were never listed in
 *    phpstan.neon while psalm.xml listed all fourteen. The files looked like
 *    coverage and provided none: every call into Imagick, Redis, Memcached, APCu,
 *    AMQP, FrankenPHP and PsySH was analysed against an unknown symbol by one of
 *    the two analysers. Wiring Imagick alone surfaced 30 real findings.
 *
 * 2. DRIFTED — the hand-written extension stubs disagreed with the extensions.
 *    Redis::sRem() was typed `int` where phpredis returns `int|false`,
 *    Redis::exists() `int` where it returns `Redis|int|bool`, and close()/quit()
 *    were absent. A merely plausible stub is worse than none: it makes the
 *    analyser confidently wrong and nothing notices. Anything phpstorm-stubs
 *    covers must come from there, because that copy is maintained against the
 *    extension and ours is not.
 */
final class AnalyzerStubsTest extends TestCase
{
    private const string STUBS_DIR = __DIR__ . '/../../../tools/php/stubs';

    /**
     * Stub files a single analyser loads on purpose, with the reason.
     *
     * @var array<string, string>
     */
    private const array SINGLE_ANALYSER_STUBS = [
        'Psr7.php' => 'Psalm-only: refines PSR-7 array returns to stop MixedArrayAccess. '
            . 'psr/http-message is a hard dependency, so this replaces a declaration rather than '
            . 'supplying a missing one, and PHPStan needs no such refinement.',
    ];

    /**
     * Stubs that deliberately override an upstream declaration known to be wrong.
     *
     * Distinct from a drifting copy, and the distinction is the whole point: a copy
     * exists because nobody checked upstream, while a correction exists because
     * someone did and upstream lost. Each entry must name the authority it was
     * checked against, so the next reader can retire it when upstream is fixed
     * instead of inheriting an unexplained shadow.
     *
     * @var array<string, array{replaces: string, reason: string}>
     */
    private const array UPSTREAM_CORRECTIONS = [
        'Grpc.php' => [
            'replaces' => 'grpc/grpc.php',
            'reason' => 'phpstorm-stubs declares Grpc\\Server::requestCall($tag_new, $tag_cancel), apparently '
                . 'transcribed from the C-core grpc_server_request_call(); the PHP method registers no parameters '
                . '(ZEND_BEGIN_ARG_INFO_EX(arginfo_requestCall, 0, 0, 0), src/php/ext/grpc/server.c). It also types '
                . 'ServerCredentials::createSsl()\'s first parameter as non-nullable while the extension parses '
                . '"s!ss" (src/php/ext/grpc/server_credentials.c). Believing either would have meant editing '
                . 'correct code to match an incorrect description.',
        ],
    ];

    /**
     * @return list<array{string}>
     */
    public static function stubFiles(): array
    {
        $files = glob(self::STUBS_DIR . '/*.php');
        self::assertIsArray($files, 'stub directory is unreadable');

        return array_map(static fn(string $path): array => [basename($path)], $files);
    }

    #[Test]
    #[DataProvider('stubFiles')]
    public function everyStubIsLoadedByPsalm(string $stub): void
    {
        self::assertStringContainsString(
            "stubs/{$stub}",
            self::readConfig('psalm.xml'),
            "tools/php/stubs/{$stub} exists but psalm.xml never loads it, so it protects nothing. "
            . 'Add it to <stubs> or delete the file.',
        );
    }

    #[Test]
    #[DataProvider('stubFiles')]
    public function everyStubIsLoadedByPhpstanUnlessDeliberatelyPsalmOnly(string $stub): void
    {
        $loaded = str_contains(self::configDirectives('phpstan.neon'), "stubs/{$stub}");

        if (isset(self::UPSTREAM_CORRECTIONS[$stub])) {
            self::assertTrue($loaded, "tools/php/stubs/{$stub} is a correction PHPStan must load.");

            return;
        }

        if (isset(self::SINGLE_ANALYSER_STUBS[$stub])) {
            self::assertFalse(
                $loaded,
                "tools/php/stubs/{$stub} is documented as single-analyser but phpstan.neon loads it. "
                . 'Either remove the entry or drop it from SINGLE_ANALYSER_STUBS.',
            );

            return;
        }

        self::assertTrue(
            $loaded,
            "tools/php/stubs/{$stub} exists but phpstan.neon never loads it, so one analyser sees "
            . 'the symbols and the other does not. Add it to scanFiles, or document why only one '
            . 'analyser should load it in AnalyzerStubsTest::SINGLE_ANALYSER_STUBS.',
        );
    }

    /**
     * A hand-written stub must not shadow a maintained one.
     */
    #[Test]
    #[DataProvider('stubFiles')]
    public function noHandWrittenStubDuplicatesPhpstormStubs(string $stub): void
    {
        $upstreamRoot = dirname(__DIR__, 3) . '/vendor/jetbrains/phpstorm-stubs';

        if (!is_dir($upstreamRoot)) {
            self::markTestSkipped('jetbrains/phpstorm-stubs is not installed');
        }

        // PhpStormStubsMap is generated by phpstorm-stubs itself and maps every
        // symbol it declares to its file — authoritative, and namespace-aware where
        // a bare-name comparison would call our Spiral\RoadRunner\Worker a duplicate
        // of pthreads' global Worker.
        $mapFile = $upstreamRoot . '/PhpStormStubsMap.php';

        if (!class_exists(PhpStormStubsMap::class, false)) {
            self::assertFileExists($mapFile);

            require $mapFile;
        }

        $upstream = array_change_key_case(PhpStormStubsMap::CLASSES) + array_change_key_case(PhpStormStubsMap::FUNCTIONS);
        $shadowed = [];

        foreach (self::declaredSymbols(self::STUBS_DIR . '/' . $stub) as $symbol) {
            if (isset($upstream[strtolower($symbol)])) {
                $shadowed[] = $symbol . ' (upstream: ' . $upstream[strtolower($symbol)] . ')';
            }
        }

        if (isset(self::UPSTREAM_CORRECTIONS[$stub])) {
            self::assertNotSame(
                [],
                $shadowed,
                "tools/php/stubs/{$stub} is registered as an upstream correction but no longer "
                . 'overrides anything phpstorm-stubs declares. Either upstream dropped the symbol, or the '
                . 'correction is obsolete — remove the entry from UPSTREAM_CORRECTIONS and the file with it.',
            );

            return;
        }

        self::assertSame(
            [],
            $shadowed,
            "tools/php/stubs/{$stub} re-declares symbols that jetbrains/phpstorm-stubs already "
            . 'provides: ' . implode(', ', $shadowed) . '. Point the analyser configs at the upstream '
            . 'file instead — a hand-written copy drifts from the extension and nothing detects it. If '
            . 'upstream is the wrong one, register the file in UPSTREAM_CORRECTIONS with the authority '
            . 'you checked it against.',
        );
    }

    /**
     * A correction must replace its upstream file, never sit alongside it.
     *
     * PHPStan's stubFiles does not win over a scanFiles declaration of the same
     * class — verified by trying it — so layering the two leaves the wrong
     * declaration in play while the config reads as though it were corrected. The
     * invariant that actually holds is exclusivity.
     */
    #[Test]
    public function upstreamCorrectionsReplaceRatherThanShadowTheirUpstreamFile(): void
    {
        foreach (self::UPSTREAM_CORRECTIONS as $stub => $correction) {
            self::assertFileExists(
                self::STUBS_DIR . '/' . $stub,
                "UPSTREAM_CORRECTIONS names {$stub}, which no longer exists. Remove the entry.",
            );

            foreach (['phpstan.neon', 'psalm.xml'] as $config) {
                $directives = self::configDirectives($config);

                self::assertStringContainsString(
                    "stubs/{$stub}",
                    $directives,
                    "{$config} must load the correction tools/php/stubs/{$stub}.",
                );
                self::assertStringNotContainsString(
                    'phpstorm-stubs/' . $correction['replaces'],
                    $directives,
                    "{$config} loads BOTH tools/php/stubs/{$stub} and the upstream "
                    . "phpstorm-stubs/{$correction['replaces']} it replaces, so the wrong declaration is "
                    . 'still in play. Remove the upstream entry — or delete the correction, if upstream '
                    . 'has since been fixed.',
                );
            }
        }
    }

    /**
     * Fully-qualified names a stub file declares, namespace changes included.
     *
     * @return list<string>
     */
    private static function declaredSymbols(string $path): array
    {
        $symbols = [];
        $namespace = '';

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^\s*namespace\s+([^;{]+)/', $line, $match) === 1) {
                $namespace = trim($match[1]) . '\\';

                continue;
            }

            if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/', $line, $match) === 1) {
                $symbols[] = $namespace . $match[1];

                continue;
            }

            if (preg_match('/^\s*function\s+(\w+)\s*\(/', $line, $match) === 1) {
                $symbols[] = $namespace . $match[1];
            }
        }

        return $symbols;
    }

    /**
     * A config's directives with its comments removed.
     *
     * Matching raw file text would let a stub "count as loaded" because a comment
     * explaining why it is NOT loaded happens to name it.
     */
    private static function configDirectives(string $name): string
    {
        $lines = [];

        foreach (explode("\n", self::readConfig($name)) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    #[Test]
    public function documentedSingleAnalyserStubsStillExist(): void
    {
        foreach (array_keys(self::SINGLE_ANALYSER_STUBS) as $stub) {
            self::assertFileExists(
                self::STUBS_DIR . '/' . $stub,
                "SINGLE_ANALYSER_STUBS names {$stub}, which no longer exists. Remove the entry.",
            );
        }
    }

    private static function readConfig(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/tools/php/' . $name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
