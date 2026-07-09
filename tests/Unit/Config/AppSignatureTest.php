<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppSignature;

#[CoversClass(AppSignature::class)]
final class AppSignatureTest extends TestCase
{
    #[Test]
    public function defaultsToNoSignal(): void
    {
        $signature = new AppSignature();

        self::assertFalse($signature->generator);
        self::assertSame('', $signature->author);
        // Off by default: nothing is disclosed unless the operator opts in.
        self::assertSame('', $signature->toHtml());
    }

    #[Test]
    public function generatorTagCarriesNoVersion(): void
    {
        $signature = new AppSignature(generator: true);

        // The generator tag must never leak a version — the goal is to signal
        // the framework, not hand an attacker a version to match CVEs against.
        self::assertSame('<meta name="generator" content="Pulsar">', $signature->toHtml());
    }

    #[Test]
    public function rendersAuthorWhenSet(): void
    {
        $signature = new AppSignature(author: 'Lenny Obez');

        self::assertSame('<meta name="author" content="Lenny Obez">', $signature->toHtml());
    }

    #[Test]
    public function rendersBothOnSeparateLines(): void
    {
        $signature = new AppSignature(generator: true, author: 'Lenny Obez');

        self::assertSame(
            "<meta name=\"generator\" content=\"Pulsar\">\n"
                . '<meta name="author" content="Lenny Obez">',
            $signature->toHtml(),
        );
    }

    #[Test]
    public function escapesOperatorSuppliedAuthor(): void
    {
        // The author is operator-controlled config; it must never break out of
        // the attribute or inject markup.
        $signature = new AppSignature(author: '"><script>alert(1)</script>');

        $html = $signature->toHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    #[Test]
    public function fromArrayParsesAndCoercesValues(): void
    {
        $signature = AppSignature::fromArray(['generator' => 1, 'author' => '  Lenny Obez  ']);

        self::assertTrue($signature->generator);
        // Surrounding whitespace is trimmed so a stray-space config value never
        // yields a padded attribute.
        self::assertSame('Lenny Obez', $signature->author);
    }

    #[Test]
    public function fromArrayAppliesDefaultsForMissingKeys(): void
    {
        $signature = AppSignature::fromArray([]);

        self::assertFalse($signature->generator);
        self::assertSame('', $signature->author);
    }

    #[Test]
    public function fromArrayIgnoresNonStringAuthor(): void
    {
        $data = ['author' => 12_345];

        $signature = AppSignature::fromArray($data);

        self::assertSame('', $signature->author);
    }
}
