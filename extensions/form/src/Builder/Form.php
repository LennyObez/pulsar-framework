<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Builder;

use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Extension\Form\Contract\FormInterface;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\ValidationResult;
use Pulsar\Http\Validation\Validator;

use function array_key_exists;
use function array_map;
use function assert;

/**
 * Concrete form instance holding fields and managing submission lifecycle.
 * @api
 */
#[Api(since: '1.0.0')]
final class Form implements FormInterface
{
    private bool $submitted = false;
    private ?ValidationResult $validationResult = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, FieldInterface> $fields
     */
    public function __construct(
        private readonly string $id,
        private readonly string $method,
        private readonly string $action,
        private readonly array $fields,
        private readonly bool $csrfEnabled,
        private readonly Validator $validator,
        private readonly ?string $csrfToken = null,
        private readonly string $csrfFieldName = '_csrf_token',
    ) {}

    #[Override]
    public function getId(): string
    {
        return $this->id;
    }

    #[Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[Override]
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, FieldInterface>
     */
    #[Override]
    public function getFields(): array
    {
        return $this->fields;
    }

    #[Override]
    public function getField(string $name): FieldInterface
    {
        if (!array_key_exists($name, $this->fields)) {
            throw FormException::invalidField($name);
        }

        return $this->fields[$name];
    }

    #[Override]
    public function hasField(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Override]
    public function submit(array $data): void
    {
        if ($this->submitted) {
            throw FormException::alreadySubmitted();
        }

        $this->submitted = true;
        $this->data = $data;

        // Populate field values from submitted data
        foreach ($this->fields as $name => $field) {
            if (array_key_exists($name, $data)) {
                $field->setValue($data[$name]);
            }
        }
    }

    #[Override]
    public function isSubmitted(): bool
    {
        return $this->submitted;
    }

    #[Override]
    public function validate(): ValidationResult
    {
        if (!$this->submitted) {
            throw FormException::notSubmitted();
        }

        /** @var array<string, list<RuleInterface>> $rules */
        $rules = [];

        foreach ($this->fields as $name => $field) {
            $fieldRules = $field->getRules();

            if ($fieldRules !== []) {
                $rules[$name] = $fieldRules;
            }
        }

        $this->validationResult = $this->validator->validate($this->data, $rules);

        return $this->validationResult;
    }

    #[Override]
    public function isValid(): bool
    {
        if ($this->validationResult === null) {
            $this->validate();
        }

        assert($this->validationResult !== null);

        return $this->validationResult->passed();
    }

    #[Override]
    public function getValidationResult(): ValidationResult
    {
        if ($this->validationResult === null) {
            throw FormException::notSubmitted();
        }

        return $this->validationResult;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getData(): array
    {
        if (!$this->submitted) {
            throw FormException::notSubmitted();
        }

        return array_map(
            static fn(FieldInterface $field): mixed => $field->getValue(),
            $this->fields,
        );
    }

    #[Override]
    public function isCsrfEnabled(): bool
    {
        return $this->csrfEnabled;
    }

    /**
     * Get the CSRF token for this form.
     */
    public function getCsrfToken(): ?string
    {
        return $this->csrfToken;
    }

    /**
     * Get the CSRF field name.
     */
    public function getCsrfFieldName(): string
    {
        return $this->csrfFieldName;
    }
}
