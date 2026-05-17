<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Pulsar\Api\Api;

/**
 * Controls how locale information is encoded in URLs.
 *
 * - `None`: No URL-based locale routing (locale from headers/query only).
 * - `PathPrefix`: Locale as first path segment (e.g., /fr/about).
 * @api
 */
#[Api(since: '1.0.0')]
enum LocaleUrlStrategy: string
{
    case None = 'none';
    case PathPrefix = 'path_prefix';
}
