<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field\Regulated;

use DateTimeImmutable;
use DateTimeInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Form\Field\AbstractField;

/**
 * Base class for regulated fields requiring consent evidence capture.
 *
 * Regulated fields capture not just the value but also proof of what
 * the user was shown and when they consented.
 * @api
 */
#[Api(since: '1.0.0')]
abstract class AbstractRegulatedField extends AbstractField
{
    protected ?ConsentEvidence $evidence = null;

    public function __construct(
        string $name,
        string $label,
        public readonly string $purpose,
        public readonly string $policyVersion,
        public readonly string $policyText,
    ) {
        parent::__construct($name, $label);
    }

    /**
     * Build consent evidence for this field.
     */
    public function captureEvidence(
        string $locale,
        string $subject,
        string $correlationId,
        string $templateHash,
    ): ConsentEvidence {
        $this->evidence = new ConsentEvidence(
            timestamp: new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            purpose: $this->purpose,
            policyVersion: $this->policyVersion,
            locale: $locale,
            subject: $subject,
            correlationId: $correlationId,
            templateHash: $templateHash,
            policyTextHash: hash('sha256', $this->policyText),
        );

        return $this->evidence;
    }

    /**
     * Get the captured evidence, if any.
     */
    public function getEvidence(): ?ConsentEvidence
    {
        return $this->evidence;
    }
}
