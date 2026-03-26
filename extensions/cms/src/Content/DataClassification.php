<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Data classification level for content and media assets.
 *
 * @psalm-api Public enum referenced across content, comments, media, and
 *            commerce DTOs to drive PII handling.
 */
#[Api(since: '1.0.0')]
enum DataClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Pii = 'pii';
}
