<?php

declare(strict_types=1);

namespace Pulsar\I18n\Region;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * HTTP middleware that resolves the visitor's region and sets request attributes.
 *
 * Sets the following request attributes for downstream controllers and templates:
 * - `_region_country`: Country instance
 * - `_region_country_code`: ISO 3166-1 alpha-2 code
 * - `_region_currency`: ISO 4217 currency code
 * - `_region_payment_methods`: list of available payment method identifiers
 */
#[Internal]
final readonly class RegionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RegionResolver $regionResolver,
        private CurrencyResolver $currencyResolver,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $country = $this->regionResolver->resolve($request);
        $currency = $this->currencyResolver->currencyFor($country);
        $paymentMethods = $this->currencyResolver->paymentMethodsForCountry($country->code);

        $request = $request
            ->withAttribute('_region_country', $country)
            ->withAttribute('_region_country_code', $country->code)
            ->withAttribute('_region_currency', $currency)
            ->withAttribute('_region_payment_methods', $paymentMethods);

        return $handler->handle($request);
    }
}
