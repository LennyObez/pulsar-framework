<?php

declare(strict_types=1);

namespace Pulsar\I18n;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

/**
 * Locale negotiation contract.
 *
 * Determines the best locale for a request based on client
 * preferences and server-supported locales.
 */
#[Api(since: '1.0.0')]
interface LocaleNegotiatorInterface
{
    /**
     * Negotiate the best locale for the request.
     *
     * @param ServerRequestInterface $request The incoming HTTP request
     * @param list<string> $supported Supported locale tags
     * @param string $default Fallback locale
     */
    public function negotiate(ServerRequestInterface $request, array $supported, string $default): string;
}
