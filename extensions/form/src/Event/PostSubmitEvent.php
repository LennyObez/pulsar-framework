<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Event;

use Pulsar\Api\Api;

/**
 * Fired after successful form submission and validation.
 * @api
 */
#[Api(since: '1.0.0')]
final class PostSubmitEvent extends FormEvent {}
