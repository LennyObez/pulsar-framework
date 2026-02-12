<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Classifies a job as a system-level job.
 *
 * System jobs are exempt from the subject ID requirement in regulated presets.
 * Use this for infrastructure jobs (health checks, cleanup tasks, metric
 * aggregation) that operate without a user or entity context.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class SystemJob {}
