<?php

declare(strict_types=1);

namespace Pulsar\Event;

use Pulsar\Api\Api;

/**
 * Marker interface for events that must be dispatched through an EventEnvelope.
 *
 * Events implementing this interface will cause the dispatcher to throw
 * EventException::envelopeRequired() if dispatched as plain objects.
 */
#[Api(since: '1.0.0')]
interface EnvelopeRequiredEvent {}
