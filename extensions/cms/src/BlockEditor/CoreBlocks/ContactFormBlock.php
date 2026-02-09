<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class ContactFormBlock implements BlockTypeInterface
{
    private const array VALID_FIELD_TYPES = ['text', 'email', 'textarea', 'tel', 'select'];

    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'contact-form';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fields' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => self::VALID_FIELD_TYPES],
                            'label' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'type', 'label'],
                    ],
                ],
                'submitText' => ['type' => 'string'],
                'action' => ['type' => 'string'],
            ],
            'required' => ['fields'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var list<array{name: string, type: string, label: string}> $fields */
        $fields = $data['fields'] ?? [];
        $submitText = htmlspecialchars((string) ($data['submitText'] ?? 'Submit'), ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars((string) ($data['action'] ?? ''), ENT_QUOTES, 'UTF-8');

        $html = "<form class=\"contact-form\" method=\"post\" action=\"{$action}\">";

        // CSRF protection
        $csrfToken = htmlspecialchars($this->csrfTokenManager->getToken(), ENT_QUOTES, 'UTF-8');
        $html .= "<input type=\"hidden\" name=\"_csrf_token\" value=\"{$csrfToken}\">";

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = htmlspecialchars((string) ($field['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $type = htmlspecialchars((string) ($field['type'] ?? 'text'), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html .= '<div class="contact-form__field">';
            $html .= "<label for=\"field-{$name}\">{$label}</label>";

            if (($field['type'] ?? '') === 'textarea') {
                $html .= "<textarea id=\"field-{$name}\" name=\"{$name}\"></textarea>";
            } else {
                $html .= "<input type=\"{$type}\" id=\"field-{$name}\" name=\"{$name}\">";
            }

            $html .= '</div>';
        }

        $html .= "<button type=\"submit\" class=\"contact-form__submit\">{$submitText}</button>";

        return $html . '</form>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['fields']) || !is_array($data['fields'])) {
            $errors[] = 'fields is required and must be an array';

            return $errors;
        }

        foreach ($data['fields'] as $index => $field) {
            if (!is_array($field)) {
                $errors[] = "fields[{$index}] must be an object";

                continue;
            }

            if (!isset($field['name']) || !is_string($field['name'])) {
                $errors[] = "fields[{$index}].name is required and must be a string";
            }

            if (!isset($field['type']) || !is_string($field['type'])) {
                $errors[] = "fields[{$index}].type is required and must be a string";
            } elseif (!in_array($field['type'], self::VALID_FIELD_TYPES, true)) {
                $errors[] = "fields[{$index}].type '{$field['type']}' is not a valid field type";
            }

            if (!isset($field['label']) || !is_string($field['label'])) {
                $errors[] = "fields[{$index}].label is required and must be a string";
            }
        }

        return $errors;
    }
}
