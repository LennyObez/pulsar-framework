<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Domain;

use function is_string;

use Pulsar\Api\Api;

/**
 * Validation rule for an admin resource field.
 */
#[Api(since: '1.0.0')]
final readonly class ValidationRule
{
    public function __construct(
        public string $rule,
        public ?string $message = null,
        public mixed $parameter = null,
    ) {}

    public function validate(mixed $value, string $fieldName): ?string
    {
        return match ($this->rule) {
            'required' => $this->validateRequired($value, $fieldName),
            'min_length' => $this->validateMinLength($value, $fieldName),
            'max_length' => $this->validateMaxLength($value, $fieldName),
            'min' => $this->validateMin($value, $fieldName),
            'max' => $this->validateMax($value, $fieldName),
            'pattern' => $this->validatePattern($value, $fieldName),
            'email' => $this->validateEmail($value, $fieldName),
            'url' => $this->validateUrl($value, $fieldName),
            default => null,
        };
    }

    private function validateRequired(mixed $value, string $fieldName): ?string
    {
        if ($value === null || $value === '') {
            return $this->message ?? "$fieldName is required";
        }
        return null;
    }

    private function validateMinLength(mixed $value, string $fieldName): ?string
    {
        if (is_string($value) && mb_strlen($value) < (int) $this->parameter) {
            return $this->message ?? "$fieldName must be at least $this->parameter characters";
        }
        return null;
    }

    private function validateMaxLength(mixed $value, string $fieldName): ?string
    {
        if (is_string($value) && mb_strlen($value) > (int) $this->parameter) {
            return $this->message ?? "$fieldName must not exceed $this->parameter characters";
        }
        return null;
    }

    private function validateMin(mixed $value, string $fieldName): ?string
    {
        if (is_numeric($value) && (float) $value < (float) $this->parameter) {
            return $this->message ?? "$fieldName must be at least $this->parameter";
        }
        return null;
    }

    private function validateMax(mixed $value, string $fieldName): ?string
    {
        if (is_numeric($value) && (float) $value > (float) $this->parameter) {
            return $this->message ?? "$fieldName must not exceed $this->parameter";
        }
        return null;
    }

    private function validatePattern(mixed $value, string $fieldName): ?string
    {
        if (is_string($value) && is_string($this->parameter) && preg_match($this->parameter, $value) !== 1) {
            return $this->message ?? "$fieldName format is invalid";
        }
        return null;
    }

    private function validateEmail(mixed $value, string $fieldName): ?string
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->message ?? "$fieldName must be a valid email address";
        }
        return null;
    }

    private function validateUrl(mixed $value, string $fieldName): ?string
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            return $this->message ?? "$fieldName must be a valid URL";
        }
        return null;
    }
}
