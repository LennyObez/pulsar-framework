<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Forms\FormSubmission;

/**
 * Dispatched when a form submission is successfully processed.
 *
 * @psalm-api Event constructed by FormSubmissionService and dispatched
 *            through the EventDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class FormSubmitted
{
    public function __construct(
        public FormSubmission $formSubmission,
    ) {}
}
