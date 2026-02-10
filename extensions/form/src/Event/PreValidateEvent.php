<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Event;

use Pulsar\Api\Api;

/**
 * Fired before validation runs on submitted form data.
 */
#[Api(since: '1.0.0')]
final class PreValidateEvent extends FormEvent {}
