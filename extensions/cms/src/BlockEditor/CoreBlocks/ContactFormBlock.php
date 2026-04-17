<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

use function bin2hex;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function random_bytes;

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
        /** @var list<mixed> $fields */
        $fields = $data['fields'] ?? [];
        /** @var mixed $rawSubmitText */
        $rawSubmitText = $data['submitText'] ?? null;
        /** @var mixed $rawAction */
        $rawAction = $data['action'] ?? null;
        $submitText = htmlspecialchars(is_string($rawSubmitText) ? $rawSubmitText : 'Submit', ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars(is_string($rawAction) ? $rawAction : '', ENT_QUOTES, 'UTF-8');

        // Generate a unique proof-of-work challenge per form render
        $powChallenge = bin2hex(random_bytes(16));
        $escapedChallenge = htmlspecialchars($powChallenge, ENT_QUOTES, 'UTF-8');

        $html = "<form class=\"contact-form\" method=\"post\" action=\"$action\" data-pow-challenge=\"$escapedChallenge\">";

        // CSRF protection
        $csrfToken = htmlspecialchars($this->csrfTokenManager->getToken(), ENT_QUOTES, 'UTF-8');
        $html .= "<input type=\"hidden\" name=\"_csrf_token\" value=\"$csrfToken\">";

        // Timing token: spam detector rejects submissions under 3 seconds
        $html .= '<input type="hidden" name="_form_rendered_at" value="' . htmlspecialchars((string) time(), ENT_QUOTES, 'UTF-8') . '">';

        // Honeypot: hidden field that bots fill; legitimate users never see it
        $html .= '<div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">';
        $html .= '<input type="text" name="_hp_field" tabindex="-1" autocomplete="off">';
        $html .= '</div>';

        // Proof-of-work fields: challenge is set server-side, nonce computed by ProofOfWork.ts
        $html .= "<input type=\"hidden\" name=\"_pow_challenge\" value=\"$escapedChallenge\">";
        $html .= '<input type="hidden" name="_pow_nonce" value="">';

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            /** @var mixed $rawName */
            $rawName = $field['name'] ?? null;
            /** @var mixed $rawType */
            $rawType = $field['type'] ?? null;
            /** @var mixed $rawLabel */
            $rawLabel = $field['label'] ?? null;
            $name = htmlspecialchars(is_string($rawName) ? $rawName : '', ENT_QUOTES, 'UTF-8');
            $type = htmlspecialchars(is_string($rawType) ? $rawType : 'text', ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars(is_string($rawLabel) ? $rawLabel : '', ENT_QUOTES, 'UTF-8');

            $html .= '<div class="contact-form__field">';
            $html .= "<label for=\"field-$name\">$label</label>";

            if (($field['type'] ?? '') === 'textarea') {
                $html .= "<textarea id=\"field-$name\" name=\"$name\"></textarea>";
            } else {
                $html .= "<input type=\"$type\" id=\"field-$name\" name=\"$name\">";
            }

            $html .= '</div>';
        }

        $html .= "<button type=\"submit\" class=\"contact-form__submit\">$submitText</button>";

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
                $errors[] = "fields[$index] must be an object";

                continue;
            }

            if (!isset($field['name']) || !is_string($field['name'])) {
                $errors[] = "fields[$index].name is required and must be a string";
            }

            if (!isset($field['type']) || !is_string($field['type'])) {
                $errors[] = "fields[$index].type is required and must be a string";
            } elseif (!in_array($field['type'], self::VALID_FIELD_TYPES, true)) {
                $errors[] = "fields[$index].type '{$field['type']}' is not a valid field type";
            }

            if (!isset($field['label']) || !is_string($field['label'])) {
                $errors[] = "fields[$index].label is required and must be a string";
            }
        }

        return $errors;
    }
}
