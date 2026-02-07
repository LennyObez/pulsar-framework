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
 */
#[Api(since: '1.0.0')]
abstract class AbstractRegulatedField extends AbstractField
{
    protected ?ConsentEvidence $evidence = null;

    public function __construct(
        string $name,
        string $label,
        private readonly string $purpose,
        private readonly string $policyVersion,
        private readonly string $policyText,
    ) {
        parent::__construct($name, $label);
    }

    /**
     * Get the machine-readable consent purpose (e.g., "marketing").
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * Get the version identifier of the policy/terms presented.
     */
    public function getPolicyVersion(): string
    {
        return $this->policyVersion;
    }

    /**
     * Get the consent text displayed to the user.
     */
    public function getPolicyText(): string
    {
        return $this->policyText;
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
