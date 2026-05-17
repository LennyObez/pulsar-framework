<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FormInterface;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;

/**
 * Fired when a regulated consent field captures evidence.
 *
 * Emitted for every regulated field submission. Listeners can
 * persist evidence to audit logs or external compliance systems.
 * @api
 */
#[Api(since: '1.0.0')]
final class ConsentCapturedEvent extends FormEvent
{
    public function __construct(
        FormInterface $form,
        public readonly string $fieldName,
        public readonly ConsentEvidence $evidence,
    ) {
        parent::__construct($form);
    }
}
