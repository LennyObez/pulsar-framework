<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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

        $request = new ServerRequest(method: 'GET', uri: '/');

        $capturedLocale = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $r) use (&$capturedLocale): ResponseInterface {
                $capturedLocale = $r->getAttribute('_locale');
                return Response::text('OK');
            });

        $middleware->process($request, $handler);

        self::assertSame('fr', $capturedLocale);
    }
}
