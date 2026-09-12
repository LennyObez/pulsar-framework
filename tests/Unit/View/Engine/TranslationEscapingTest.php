<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\TranslateDirective;
use Pulsar\View\Directive\TranslateRawDirective;
use Pulsar\View\Engine\TemplateCache;
use Pulsar\View\Engine\TemplateCompiler;
use Pulsar\View\Engine\TemplateEngine;
use Pulsar\View\ViewConfig;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A hostile catalog value is rendered escaped by every sanctioned form.
 *
 * A translation is not trusted input. Catalogs are shipped by extensions, they
 * are edited by translators, and in a regulated deployment they can be loaded
 * from a database a customer administrator writes to. So the test plants a
 * `<script>` payload as the translated value and watches what the template
 * layer does with it, rather than reasoning about which function is called.
 */
#[CoversClass(TranslateDirective::class)]
#[CoversClass(TranslateRawDirective::class)]
final class TranslationEscapingTest extends TestCase
{
    private const string HOSTILE = '<script>alert("xss")</script>';

    private string $templateDir;

    private string $cacheDir;

    private TemplateEngine $engine;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_translation_escaping_' . uniqid();
        $this->templateDir = $base . DIRECTORY_SEPARATOR . 'views';
        $this->cacheDir = $base . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->templateDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);

        $config = new ViewConfig(
            templatePaths: [$this->templateDir],
            cachePath: $this->cacheDir,
        );

        $compiler = new TemplateCompiler($config, new TemplateCache($this->cacheDir));
        $compiler->registerDirective('t', new TranslateDirective()->compile(...));
        $compiler->registerDirective('tRaw', new TranslateRawDirective()->compile(...));

        $this->engine = new TemplateEngine($compiler);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive(dirname($this->templateDir));
    }

    /**
     * The whole point of the round: the value a translator controls must not be
     * able to close the tag it is rendered into.
     */
    #[Test]
    public function theDirectiveEscapesAHostileTranslationValue(): void
    {
        $this->writeTemplate('greeting', "<h1>@t('greeting')</h1>");

        $output = $this->render('greeting');

        self::assertSame(
            '<h1>&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;</h1>',
            $output,
        );
        self::assertStringNotContainsString('<script>', $output);
    }

    /**
     * Escaping has to survive the attribute context too, which is why the
     * directive uses ENT_QUOTES: a value that can emit a bare `"` escapes the
     * attribute and gets an event handler in for free.
     */
    #[Test]
    public function theDirectiveEscapesQuotesInsideAnAttribute(): void
    {
        $this->writeTemplate('attribute', "<a title=\"@t('greeting')\">x</a>");

        $output = $this->render('attribute');

        self::assertStringNotContainsString('"><', $output);
        self::assertStringContainsString('&quot;', $output);
    }

    /**
     * `@tRaw` is the one deliberate hole, and it stays open on purpose: a
     * translation may legitimately carry markup (a link inside a sentence
     * cannot be assembled outside the catalog without breaking word order in
     * half the target languages). It is a different, greppable spelling, so a
     * reviewer can find every raw site in the tree with one search — which is
     * exactly what the `@`-inside-a-PHP-tag form made impossible.
     */
    #[Test]
    public function theRawDirectiveIsTheOnlyFormThatEmitsMarkupVerbatim(): void
    {
        $this->writeTemplate('raw', "<h1>@tRaw('greeting')</h1>");

        self::assertSame('<h1>' . self::HOSTILE . '</h1>', $this->render('raw'));
    }

    private function render(string $template): string
    {
        return $this->engine->render($template, [
            '__translator' => new class {
                /**
                 * @param array<string, mixed> $parameters
                 */
                public function translate(
                    string $key,
                    array $parameters = [],
                    ?string $locale = null,
                    string $domain = 'messages',
                ): string {
                    unset($key, $parameters, $locale, $domain);

                    return TranslationEscapingTest::hostileValue();
                }
            },
        ]);
    }

    /**
     * Exposed so the anonymous translator can reach the payload constant.
     */
    public static function hostileValue(): string
    {
        return self::HOSTILE;
    }

    private function writeTemplate(string $name, string $content): void
    {
        file_put_contents($this->templateDir . DIRECTORY_SEPARATOR . $name . '.pulse.php', $content);
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full)) {
                $this->removeRecursive($full);

                continue;
            }

            if (is_file($full)) {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
