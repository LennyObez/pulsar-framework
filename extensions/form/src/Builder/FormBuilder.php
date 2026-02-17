<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Builder;

use Pulsar\Api\Api;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Contract\AntivirusPort;
use Pulsar\Extension\Form\Contract\FieldInterface;
use Pulsar\Extension\Form\Csrf\FormCsrfManager;
use Pulsar\Extension\Form\Exception\FormException;
use Pulsar\Extension\Form\Exception\UploadException;
use Pulsar\Extension\Form\Field\FileField;
use Pulsar\Http\Validation\Validator;

use function array_key_exists;

/**
 * Fluent form builder for constructing form instances.
 *
 * @example
 * $form = $builder
 *     ->id('login')
 *     ->action('/login')
 *     ->method('POST')
 *     ->add(new TextField('username', 'Username'))
 *     ->add(new PasswordField('password', 'Password'))
 *     ->csrf()
 *     ->build();
 */
#[Api(since: '1.0.0')]
final class FormBuilder
{
    /** @var array<string, FieldInterface> */
    private array $fields = [];

    private string $id = '';
    private string $method = 'POST';
    private string $action = '';
    private bool $csrfEnabled = true;

    public function __construct(
        private readonly FormConfig $config,
        private readonly Validator $validator,
        private readonly ?FormCsrfManager $csrfManager = null,
        private readonly ?AntivirusPort $antivirusPort = null,
    ) {
        $this->csrfEnabled = $config->csrf->enabled;
    }

    /**
     * Set the form identifier (used for CSRF token binding).
     */
    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Set the HTTP method.
     */
    public function method(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Set the form action URL.
     */
    public function action(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Add a field to the form.
     */
    public function add(FieldInterface $field): self
    {
        $this->fields[$field->getName()] = $field;

        return $this;
    }

    /**
     * Remove a field from the form.
     */
    public function remove(string $name): self
    {
        unset($this->fields[$name]);

        return $this;
    }

    /**
     * Get a field by name.
     */
    public function get(string $name): FieldInterface
    {
        if (!array_key_exists($name, $this->fields)) {
            throw FormException::invalidField($name);
        }

        return $this->fields[$name];
    }

    /**
     * Enable or disable CSRF protection.
     */
    public function csrf(bool $enabled = true): self
    {
        $this->csrfEnabled = $enabled;

        return $this;
    }

    /**
     * Build the form instance.
     *
     * @throws FormException On configuration errors
     * @throws UploadException When regulated preset requires antivirus for file uploads
     */
    public function build(): Form
    {
        if ($this->id === '') {
            throw FormException::configurationError('Form ID is required');
        }

        $this->validateUploadConfiguration();

        $csrfToken = null;

        if ($this->csrfEnabled && $this->csrfManager !== null) {
            $csrfToken = $this->csrfManager->generate($this->id, $this->action);
        }

        return new Form(
            id: $this->id,
            method: $this->method,
            action: $this->action,
            fields: $this->fields,
            csrfEnabled: $this->csrfEnabled,
            validator: $this->validator,
            csrfToken: $csrfToken,
            csrfFieldName: $this->config->csrf->fieldName,
        );
    }

    /**
     * Ensure file upload configuration is valid for regulated presets.
     */
    private function validateUploadConfiguration(): void
    {
        if (!$this->config->upload->regulatedPreset) {
            return;
        }

        $hasFileFields = false;

        foreach ($this->fields as $field) {
            if ($field instanceof FileField) {
                $hasFileFields = true;
                break;
            }
        }

        if ($hasFileFields && $this->antivirusPort === null) {
            throw UploadException::antivirusNotConfigured();
        }
    }
}
