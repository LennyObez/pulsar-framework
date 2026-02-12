<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Locale;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\I18n\Locale\LocaleNegotiator;

#[CoversClass(LocaleNegotiator::class)]
final class LocaleNegotiatorTest extends TestCase
{
    private LocaleNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new LocaleNegotiator();
    }

    #[Test]
    public function queryParameterHasHighestPriority(): void
    {
        $request = $this->makeRequest(query: ['locale' => 'fr'], acceptLanguage: 'en');

        $result = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('fr', $result);
    }

    #[Test]
    public function requestAttributeOverridesHeader(): void
    {
        $request = $this->makeRequest(attributes: ['_locale' => 'de'], acceptLanguage: 'fr');

        $result = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('de', $result);
    }

    #[Test]
    public function acceptLanguageHeaderUsedWhenNoQueryOrAttribute(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'fr-CA,fr;q=0.9,en;q=0.8');

        $result = $this->negotiator->negotiate($request, ['en', 'fr'], 'en');

        self::assertSame('fr', $result);
    }

    #[Test]
    public function exactMatchPreferredOverPrefix(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'fr_CA');

        $result = $this->negotiator->negotiate($request, ['fr', 'fr_CA'], 'en');

        self::assertSame('fr_CA', $result);
    }

    #[Test]
    public function languagePrefixMatch(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'fr-CA');

        $result = $this->negotiator->negotiate($request, ['fr', 'en'], 'en');

        self::assertSame('fr', $result);
    }

    #[Test]
    public function fallsBackToDefaultWhenNoMatch(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'zh');

        $result = $this->negotiator->negotiate($request, ['en', 'fr'], 'en');

        self::assertSame('en', $result);
    }

    #[Test]
    public function fallsBackToDefaultWithEmptySupportedList(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'fr');

        $result = $this->negotiator->negotiate($request, [], 'en');

        self::assertSame('en', $result);
    }

    #[Test]
    public function qualitySortingRespected(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'en;q=0.5,de;q=0.9,fr;q=0.7');

        $result = $this->negotiator->negotiate($request, ['en', 'fr', 'de'], 'en');

        self::assertSame('de', $result);
    }

    #[Test]
    public function malformedQValueTreatedAsZero(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'fr;q=abc,en;q=0.5');

        $result = $this->negotiator->negotiate($request, ['en', 'fr'], 'en');

        self::assertSame('en', $result);
    }

    #[Test]
    public function noAcceptLanguageHeaderFallsToDefault(): void
    {
        $request = $this->makeRequest();

        $result = $this->negotiator->negotiate($request, ['en', 'fr'], 'en');

        self::assertSame('en', $result);
    }

    #[Test]
    public function unsupportedQueryParamFallsThrough(): void
    {
        $request = $this->makeRequest(query: ['locale' => 'zh'], acceptLanguage: 'fr');

        $result = $this->negotiator->negotiate($request, ['en', 'fr'], 'en');

        self::assertSame('fr', $result);
    }

    #[Test]
    public function reversePrefixMatchSupported(): void
    {
        $request = $this->makeRequest(acceptLanguage: 'fr');

        $result = $this->negotiator->negotiate($request, ['fr_CA', 'en'], 'en');

        self::assertSame('fr_CA', $result);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $attributes
     */
    private function makeRequest(
        array $query = [],
        array $attributes = [],
        ?string $acceptLanguage = null,
    ): Request {
        $headers = [];

        if ($acceptLanguage !== null) {
            $headers['accept-language'] = [$acceptLanguage];
        }

        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag($headers),
            body: '',
            query: $query,
            attributes: $attributes,
        );
    }
}
