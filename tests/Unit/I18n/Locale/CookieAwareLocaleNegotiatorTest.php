<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\I18n\Locale\CookieAwareLocaleNegotiator;
use Pulsar\I18n\Locale\LocaleNegotiator;

#[CoversClass(CookieAwareLocaleNegotiator::class)]
final class CookieAwareLocaleNegotiatorTest extends TestCase
{
    private CookieAwareLocaleNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new CookieAwareLocaleNegotiator(new LocaleNegotiator());
    }

    #[Test]
    public function cookieTakesPriorityOverAcceptLanguage(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn(['pulsar_locale' => 'fr']);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('en-US,en;q=0.9');

        $locale = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('fr', $locale);
    }

    #[Test]
    public function sessionAttributeTakesPriorityOverAcceptLanguage(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn([]);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): ?string => match ($name) {
                'session_locale' => 'de',
                default => null,
            },
        );
        $request->method('getHeaderLine')->willReturn('en-US');

        $locale = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('de', $locale);
    }

    #[Test]
    public function cookieTakesPriorityOverSession(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn(['pulsar_locale' => 'nl']);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): ?string => match ($name) {
                'session_locale' => 'de',
                default => null,
            },
        );
        $request->method('getHeaderLine')->willReturn('en');

        $locale = $this->negotiator->negotiate($request, ['en', 'nl', 'de'], 'en');

        self::assertSame('nl', $locale);
    }

    #[Test]
    public function unsupportedCookieLocaleFallsThrough(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn(['pulsar_locale' => 'ja']);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('fr-FR');

        $locale = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('fr', $locale);
    }

    #[Test]
    public function fallsBackToDefaultWhenNothingMatches(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn([]);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('');

        $locale = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('en', $locale);
    }

    #[Test]
    public function emptySupportedLocalesReturnsDefault(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn(['pulsar_locale' => 'fr']);

        $locale = $this->negotiator->negotiate($request, [], 'en');

        self::assertSame('en', $locale);
    }

    #[Test]
    public function emptyCookieValueIsIgnored(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn(['pulsar_locale' => '']);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('de');

        $locale = $this->negotiator->negotiate($request, ['en', 'de'], 'en');

        self::assertSame('de', $locale);
    }

    #[Test]
    public function delegatesToInnerNegotiatorForQueryParam(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn([]);
        $request->method('getQueryParams')->willReturn(['locale' => 'de']);
        $request->method('getAttribute')->willReturn(null);
        $request->method('getHeaderLine')->willReturn('');

        $locale = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('de', $locale);
    }
}
