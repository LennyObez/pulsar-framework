<?php

declare(strict_types=1);

namespace Pulsar\Event\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks an event class as requiring envelope-based dispatch.
 *
 * Events with this attribute must be dispatched via EventEnvelope.
 * Dispatching them as plain objects will throw EventException::envelopeRequired().
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class RequiresEnvelope {}
