<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\I18n\LocaleResolver;

#[CoversClass(LocaleResolver::class)]
final class LocaleResolverTest extends TestCase
{
    #[Test]
    public function resolveExtractsLocaleFromUrlPrefix(): void
    {
        $resolver = new LocaleResolver();
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr', 'de'],
        );

        $request = $this->buildRequest('/fr/about');
        $locale = $resolver->resolve($request, $config);

        self::assertSame('fr', $locale);
    }

    #[Test]
    public function resolveReturnsDefaultLocaleWhenNoPrefix(): void
    {
        $resolver = new LocaleResolver();
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
        );

        $request = $this->buildRequest('/about');
        $locale = $resolver->resolve($request, $config);

        self::assertSame('en', $locale);
    }

    #[Test]
    public function resolveReturnsDefaultForUnsupportedPrefix(): void
    {
        $resolver = new LocaleResolver();
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
        );

        $request = $this->buildRequest('/it/home');
        $locale = $resolver->resolve($request, $config);

        self::assertSame('en', $locale);
    }

    #[Test]
    public function resolveWorksWithRootPath(): void
    {
        $resolver = new LocaleResolver();
        $config = new CmsConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
        );

        $request = $this->buildRequest('/');
        $locale = $resolver->resolve($request, $config);

        self::assertSame('en', $locale);
    }

    private function buildRequest(string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}
