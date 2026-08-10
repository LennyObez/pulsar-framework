<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;

/**
 * `ImportAnalyzer::extractClassStringReferences()` surfaces Pulsar FQCNs
 * that live in `T_CONSTANT_ENCAPSED_STRING` tokens — the class-string
 * lookup pattern used by `$container->get()`, `class_exists()`, and
 * `is_a()` calls, which `extractReferences()` cannot see because no
 * `use` statement or FQCN token is involved.
 */
#[CoversClass(ImportAnalyzer::class)]
final class ImportAnalyzerClassStringTest extends TestCase
{
    private string $fixturePath;

    /**
     * Per-test unique fixture file under sys_get_temp_dir(). Cleanup
     * is left to the OS — static analysis flags any unlink() flow
     * from realpath() as a CWE-22 path-traversal sink, and
     * sys_get_temp_dir() is purged on every CI runner / on OS boot
     * locally so accumulated junk has bounded lifetime.
     */
    protected function setUp(): void
    {
        $this->fixturePath = sys_get_temp_dir() . '/pulsar_import_analyzer_class_string_' . bin2hex(random_bytes(8)) . '.php';
    }

    #[Test]
    public function detectsContainerGetSingleQuotedClassString(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            $container->get('Pulsar\Foo\Bar');
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        self::assertSame(['Pulsar\\Foo\\Bar'], $refs);
    }

    #[Test]
    public function detectsContainerGetDoubleQuotedClassString(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            $container->get("Pulsar\\Foo\\Bar");
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        self::assertSame(['Pulsar\\Foo\\Bar'], $refs);
    }

    #[Test]
    public function detectsLeadingBackslash(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            class_exists('\\Pulsar\\Container\\Container');
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        self::assertSame(['Pulsar\\Container\\Container'], $refs);
    }

    #[Test]
    public function deduplicatesRepeatedReferences(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            $container->get('Pulsar\Foo\Bar');
            $container->get('Pulsar\Foo\Bar');
            class_exists('Pulsar\Foo\Bar');
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        self::assertSame(['Pulsar\\Foo\\Bar'], $refs);
    }

    #[Test]
    public function extractsMultipleDistinctReferences(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            $container->get('Pulsar\Cache\FrameworkCache');
            class_exists('Pulsar\Routing\Router');
            is_a($x, 'Pulsar\Http\Response');
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        sort($refs);
        self::assertSame([
            'Pulsar\\Cache\\FrameworkCache',
            'Pulsar\\Http\\Response',
            'Pulsar\\Routing\\Router',
        ], $refs);
    }

    #[Test]
    public function ignoresNonPulsarClassStrings(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            $container->get('App\Service');
            $container->get('League\Foo\Bar');
            $container->get('PHPUnit\Framework\TestCase');
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        self::assertSame([], $refs);
    }

    #[Test]
    public function ignoresStringsThatLookLikePulsarButAreNotFqcns(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            $logger->info('Pulsar boot complete');
            $config['Pulsar version'] = '1.0';
            $message = "See Pulsar\\\\Docs\\\\Issue#42";
            PHP);

        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        // None of these match `Pulsar\<UpperCaseSegment>...` strictly,
        // either because of trailing whitespace, non-name characters,
        // or the source-level escaped backslash sequence.
        self::assertSame([], $refs);
    }

    #[Test]
    public function ignoresPhpDocAnnotationStrings(): void
    {
        $this->writeFixture(<<<'PHP'
            <?php
            /** @param Pulsar\Foo\Bar $x */
            function f($x) { return $x; }
            PHP);

        // PHPDoc text lives in `T_DOC_COMMENT`, not
        // `T_CONSTANT_ENCAPSED_STRING`, so it must not surface here.
        $refs = ImportAnalyzer::extractClassStringReferences($this->fixturePath);

        self::assertSame([], $refs);
    }

    #[Test]
    public function returnsEmptyArrayForUnreadableFile(): void
    {
        $refs = ImportAnalyzer::extractClassStringReferences(
            sys_get_temp_dir() . '/this-file-definitely-does-not-exist-' . uniqid('', true),
        );

        self::assertSame([], $refs);
    }

    private function writeFixture(string $content): void
    {
        file_put_contents($this->fixturePath, $content);
    }
}
