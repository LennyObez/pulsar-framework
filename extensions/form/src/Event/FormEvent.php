<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FormInterface;

/**
 * Base event for form lifecycle events.
 */
#[Api(since: '1.0.0')]
abstract class FormEvent
{
    public function __construct(
        public readonly FormInterface $form,
    ) {}
}
