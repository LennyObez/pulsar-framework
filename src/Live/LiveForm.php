<?php

declare(strict_types=1);

namespace Pulsar\Live;

use Pulsar\Api\Api;
use ReflectionClass;

use function is_string;

/**
 * Base class for typed form objects used with Live components.
 *
 * Form objects encapsulate form state and validation, separating
 * form concerns from component logic. Properties are automatically
 * synced via wire:model.
 *
 * Example:
 *   final class LoginForm extends LiveForm {
 *       public string $email = '';
 *       public string $password = '';
 *
 *       public function rules(): array {
 *           return [
 *               'email' => ['required', 'email'],
 *               'password' => ['required', 'min:8'],
 *           ];
 *       }
 *   }
 * @api
 */
#[Api(since: '1.0.0')]
abstract class LiveForm
{
    /** @var array<string, list<string>> Validation errors keyed by field name */
    private array $errors = [];

    /**
     * Define validation rules for form fields.
     *
     * @return array<string, list<string>> Rules keyed by property name
     */
    abstract public function rules(): array;

    /**
     * Validate the form against its rules.
     *
     * @return bool True if validation passes
     */
    public function validate(): bool
    {
        $this->errors = [];
        $rules = $this->rules();

        foreach ($rules as $field => $fieldRules) {
            if (!property_exists($this, $field)) {
                continue;
            }

            /** @var mixed $value */
            $value = $this->{$field};

            foreach ($fieldRules as $rule) {
                $error = $this->applyRule($field, $value, $rule);

                if ($error !== null) {
                    $this->errors[$field][] = $error;
                }
            }
        }

        return $this->errors === [];
    }

    /**
     * Get all validation errors.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field.
     *
     * @return list<string>
     */
    public function fieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Whether the form has any validation errors.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Whether a specific field has errors.
     */
    public function hasFieldError(string $field): bool
    {
        return isset($this->errors[$field]) && $this->errors[$field] !== [];
    }

    /**
     * Reset the form to default values.
     */
    public function reset(): void
    {
        $this->errors = [];
        $ref = new ReflectionClass(static::class);

        foreach ($this->rules() as $field => $_) {
            if (property_exists($this, $field)) {
                $prop = $ref->getProperty($field);
                if ($prop->hasDefaultValue()) {
                    $this->{$field} = $prop->getDefaultValue();
                }
            }
        }
    }

    /**
     * Fill form values from an array.
     *
     * @param array<string, mixed> $data
     */
    public function fill(array $data): void
    {
        /** @var mixed $value */
        foreach ($data as $field => $value) {
            if (property_exists($this, $field)) {
                $this->{$field} = $value;
            }
        }
    }

    /**
     * Get all form data as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        foreach ($this->rules() as $field => $_) {
            if (property_exists($this, $field)) {
                $data = [...$data, $field => $this->{$field}];
            }
        }

        return $data;
    }

    /**
     * Add a manual validation error.
     */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function applyRule(string $field, mixed $value, string $rule): ?string
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $parameter = $parts[1] ?? null;

        return match ($ruleName) {
            'required' => $this->validateRequired($field, $value),
            'email' => $this->validateEmail($field, $value),
            'min' => $this->validateMin($field, $value, $parameter),
            'max' => $this->validateMax($field, $value, $parameter),
            'url' => $this->validateUrl($field, $value),
            'numeric' => $this->validateNumeric($field, $value),
            'confirmed' => $this->validateConfirmed($field, $value),
            default => null,
        };
    }

    private function validateRequired(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return "{$field} is required.";
        }

        return null;
    }

    private function validateEmail(string $field, mixed $value): ?string
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return "{$field} must be a valid email address.";
        }

        return null;
    }

    private function validateMin(string $field, mixed $value, ?string $param): ?string
    {
        $min = $param !== null ? (int) $param : 0;

        if (is_string($value) && mb_strlen($value) < $min) {
            return "{$field} must be at least {$min} characters.";
        }

        if (is_numeric($value) && (float) $value < $min) {
            return "{$field} must be at least {$min}.";
        }

        return null;
    }

    private function validateMax(string $field, mixed $value, ?string $param): ?string
    {
        $max = $param !== null ? (int) $param : 0;

        if (is_string($value) && mb_strlen($value) > $max) {
            return "{$field} must not exceed {$max} characters.";
        }

        if (is_numeric($value) && (float) $value > $max) {
            return "{$field} must not exceed {$max}.";
        }

        return null;
    }

    private function validateUrl(string $field, mixed $value): ?string
    {
        if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            return "{$field} must be a valid URL.";
        }

        return null;
    }

    private function validateNumeric(string $field, mixed $value): ?string
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            return "{$field} must be numeric.";
        }

        return null;
    }

    private function validateConfirmed(string $field, mixed $value): ?string
    {
        $confirmationField = $field . '_confirmation';

        if (property_exists($this, $confirmationField)) {
            /** @var mixed $confirmation */
            $confirmation = $this->{$confirmationField};

            if ($value !== $confirmation) {
                return "{$field} confirmation does not match.";
            }
        }

        return null;
    }
}
