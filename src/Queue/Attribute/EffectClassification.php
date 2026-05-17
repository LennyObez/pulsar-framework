<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Pulsar\Api\Api;

/**
 * Categorizes the side-effect profile of a queued job.
 *
 * Every job must declare exactly one effect classification via the corresponding
 * attribute: #[Idempotent], #[ReadOnly], or #[NonIdempotent]. The classification
 * drives retry policy and regulated-preset enforcement.
 * @api
 */
#[Api(since: '1.0.0')]
enum EffectClassification: string
{
    case Idempotent = 'idempotent';
    case ReadOnly = 'read_only';
    case NonIdempotent = 'non_idempotent';
}
