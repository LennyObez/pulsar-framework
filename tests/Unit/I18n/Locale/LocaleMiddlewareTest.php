<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\I18n\Locale\LocaleMiddleware;
use Pulsar\I18n\LocaleNegotiatorInterface;
use Pulsar\I18n\TranslatorInterface;

#[CoversClass(LocaleMiddleware::class)]
final class LocaleMiddlewareTest extends TestCase
{
    #[Test]
    public function setsLocaleAttributeOnRequest(): void
    {
        $config = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en', 'fr'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        );

        $negotiator = $this->createStub(LocaleNegotiatorInterface::class);
        $negotiator->method('negotiate')->willReturn('fr');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('setLocale')->with('fr');

        $middleware = new LocaleMiddleware($negotiator, $config, $translator);

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $capturedLocale = null;
        $next = static function (Request $r) use (&$capturedLocale): Response {
            $capturedLocale = $r->attribute('_locale');
            return Response::text('OK');
        };

        $middleware->process($request, $next);

        self::assertSame('fr', $capturedLocale);
    }
}
