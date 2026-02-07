<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FormInterface;

/**
 * Fired before form data is processed.
 *
 * Listeners can inspect or modify the raw submission data.
 */
#[Api(since: '1.0.0')]
final class PreSubmitEvent extends FormEvent
{
    /**
     * @param array<string, mixed> $data Raw submission data
     */
    public function __construct(
        FormInterface $form,
        public array $data,
    ) {
        parent::__construct($form);
    }
}
